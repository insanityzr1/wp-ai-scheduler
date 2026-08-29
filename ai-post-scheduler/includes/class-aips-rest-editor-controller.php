<?php
/**
 * REST Editor Controller
 *
 * Exposes REST API endpoints for the Gutenberg block editor semantic link
 * inserter and anchor suggestion sidebar.
 *
 * @package AI_Post_Scheduler
 * @since 3.7.0
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class AIPS_REST_Editor_Controller
 *
 * Manages REST routes under the /aips/v1/editor namespace.
 */
class AIPS_REST_Editor_Controller extends WP_REST_Controller {

	/**
	 * Namespace for AIPS REST endpoints.
	 *
	 * @var string
	 */
	protected $namespace = 'aips/v1';

	/**
	 * Resource route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'editor';

	/**
	 * @var AIPS_Relationships_Repository
	 */
	private $relationships_repo;

	/**
	 * @var AIPS_Embeddings_Repository
	 */
	private $embeddings_repo;

	/**
	 * @var AIPS_Embeddings_Service
	 */
	private $embeddings_service;

	/**
	 * @var AIPS_Internal_Link_Inserter_Service
	 */
	private $inserter_service;

	/**
	 * Initialize the controller and its dependencies.
	 *
	 * @param AIPS_Relationships_Repository|null     $relationships_repo Relationships repository.
	 * @param AIPS_Embeddings_Repository|null        $embeddings_repo    Embeddings repository.
	 * @param AIPS_Embeddings_Service|null           $embeddings_service Embeddings service.
	 * @param AIPS_Internal_Link_Inserter_Service|null $inserter_service   Link inserter service.
	 */
	public function __construct(
		$relationships_repo = null,
		$embeddings_repo = null,
		$embeddings_service = null,
		$inserter_service = null
	) {
		$container                = AIPS_Container::get_instance();
		$this->relationships_repo = $relationships_repo ?: ($container->has(AIPS_Relationships_Repository::class) ? $container->make(AIPS_Relationships_Repository::class) : new AIPS_Relationships_Repository());
		$this->embeddings_repo    = $embeddings_repo    ?: ($container->has(AIPS_Embeddings_Repository::class) ? $container->make(AIPS_Embeddings_Repository::class) : new AIPS_Embeddings_Repository());
		$this->embeddings_service = $embeddings_service ?: ($container->has(AIPS_Embeddings_Service::class) ? $container->make(AIPS_Embeddings_Service::class) : new AIPS_Embeddings_Service());
		$this->inserter_service   = $inserter_service   ?: ($container->has(AIPS_Internal_Link_Inserter_Service::class) ? $container->make(AIPS_Internal_Link_Inserter_Service::class) : new AIPS_Internal_Link_Inserter_Service());
	}

	/**
	 * Register the REST routes for the editor sidebar.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/link-suggestions',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'get_link_suggestions'),
					'permission_callback' => array($this, 'check_editor_permissions'),
					'args'                => array(
						'post_id' => array(
							'description'       => __('Current post ID being edited, if saved.', 'ai-post-scheduler'),
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 0,
						),
						'content' => array(
							'description'       => __('Active draft or block text content.', 'ai-post-scheduler'),
							'type'              => 'string',
							'sanitize_callback' => 'wp_kses_post',
							'default'           => '',
						),
						'limit' => array(
							'description'       => __('Maximum suggestions to return.', 'ai-post-scheduler'),
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 5,
						),
						'min_similarity' => array(
							'description'       => __('Minimum cosine similarity threshold.', 'ai-post-scheduler'),
							'type'              => 'number',
							'sanitize_callback' => function ($val) {
								return (float) $val;
							},
							'default'           => 0.60,
						),
						'query' => array(
							'description'       => __('Search keyword or topic override.', 'ai-post-scheduler'),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
						'target_post_type' => array(
							'description'       => __('Filter suggestions by post type.', 'ai-post-scheduler'),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'default'           => '',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/find-anchors',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'find_anchors'),
					'permission_callback' => array($this, 'check_editor_permissions'),
					'args'                => array(
						'source_content' => array(
							'description'       => __('Draft or block content to analyze.', 'ai-post-scheduler'),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'wp_kses_post',
						),
						'target_post_id' => array(
							'description'       => __('Target post ID to link toward.', 'ai-post-scheduler'),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'post_id' => array(
							'description'       => __('Source post ID if editing an existing post.', 'ai-post-scheduler'),
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 0,
						),
						'anchor_text' => array(
							'description'       => __('Optional preferred anchor phrase.', 'ai-post-scheduler'),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
						'limit' => array(
							'description'       => __('Number of anchor locations to return.', 'ai-post-scheduler'),
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 3,
						),
					),
				),
			)
		);
	}

	/**
	 * Check permissions for editor actions.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True if permitted, WP_Error otherwise.
	 */
	public function check_editor_permissions($request) {
		$post_id = absint($request->get_param('post_id'));

		if ($post_id > 0) {
			if (!current_user_can('edit_post', $post_id)) {
				return new WP_Error(
					'rest_forbidden',
					__('You do not have permission to edit this post.', 'ai-post-scheduler'),
					array('status' => rest_authorization_required_code())
				);
			}
			return true;
		}

		if (!current_user_can('edit_posts')) {
			return new WP_Error(
				'rest_forbidden',
				__('You do not have permission to edit posts.', 'ai-post-scheduler'),
				array('status' => rest_authorization_required_code())
			);
		}

		return true;
	}

	/**
	 * Retrieve top semantically related internal link suggestions.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response Response object.
	 */
	public function get_link_suggestions($request) {
		$post_id          = absint($request->get_param('post_id'));
		$content          = (string) $request->get_param('content');
		$query            = trim((string) $request->get_param('query'));
		$target_post_type = sanitize_key((string) $request->get_param('target_post_type'));
		$limit            = max(1, min(20, (int) $request->get_param('limit')));
		$min_similarity   = max(0.0, min(1.0, (float) $request->get_param('min_similarity')));

		// Fallback: If content is empty but post_id is provided, retrieve source post content
		if (empty($content) && empty($query) && $post_id > 0) {
			$source_post = get_post($post_id);
			if ($source_post && !empty($source_post->post_content)) {
				$content = $source_post->post_content;
			}
		}

		$suggestions = array();

		// Priority 1: Check precomputed relationships if post exists AND no custom search query override
		if ($post_id > 0 && empty($query)) {
			$related_rows = $this->relationships_repo->get_related('post', $post_id, $limit * 2, $min_similarity);

			if (!empty($related_rows)) {
				foreach ($related_rows as $row) {
					if (count($suggestions) >= $limit) {
						break;
					}

					$target_id   = (int) $row->target_id;
					$target_post = get_post($target_id);

					if (!$target_post || 'publish' !== $target_post->post_status) {
						continue;
					}

					if (!empty($target_post_type) && $target_post->post_type !== $target_post_type) {
						continue;
					}

					$similarity     = (float) $row->similarity;
					$similarity_pct = (int) round($similarity * 100);
					$excerpt        = !empty($target_post->post_excerpt) ? $target_post->post_excerpt : wp_trim_words($target_post->post_content, 20);

					$suggestions[] = array(
						'id'             => $target_id,
						'title'          => html_entity_decode(get_the_title($target_id), ENT_QUOTES, 'UTF-8'),
						'url'            => get_permalink($target_id),
						'post_type'      => $target_post->post_type,
						'similarity'     => $similarity,
						'similarity_pct' => $similarity_pct,
						'excerpt'        => wp_strip_all_tags($excerpt),
						'is_precomputed' => true,
					);
				}
			}
		}

		// Priority 2: On-the-fly vector similarity when query is provided, or no precomputed links, or drafting new content
		$text_to_embed = !empty($query) ? $query : wp_strip_all_tags($content);
		if (empty($suggestions) && !empty($text_to_embed)) {
			if (strlen($text_to_embed) >= 3 && $this->embeddings_service->is_embeddings_supported()) {
				$draft_embedding = $this->embeddings_service->generate_embedding(substr($text_to_embed, 0, 1500));

				if (!is_wp_error($draft_embedding) && is_array($draft_embedding)) {
					$supported_types = !empty($target_post_type)
						? array($target_post_type)
						: apply_filters('aips_editor_indexable_post_types', array('post', 'page'));

					$candidate_rows  = $this->embeddings_repo->get_all_for_similarity('post', $supported_types, 'publish');
					$candidates      = array();

					foreach ($candidate_rows as $c_row) {
						$c_id = (int) $c_row->object_id;
						if ($post_id > 0 && $c_id === $post_id) {
							continue;
						}

						$c_vec = json_decode($c_row->embedding, true);
						if (!empty($c_vec)) {
							$candidates[] = array(
								'id'        => $c_id,
								'embedding' => $c_vec,
							);
						}
					}

					$nearest = $this->embeddings_service->find_nearest_neighbors($draft_embedding, $candidates, $limit * 2);

					foreach ($nearest as $item) {
						if (count($suggestions) >= $limit) {
							break;
						}

						$t_id   = (int) $item['id'];
						$sim    = (float) $item['similarity'];

						if ($sim < $min_similarity) {
							continue;
						}

						$target_post = get_post($t_id);
						if (!$target_post || 'publish' !== $target_post->post_status) {
							continue;
						}

						if (!empty($target_post_type) && $target_post->post_type !== $target_post_type) {
							continue;
						}

						$similarity_pct = (int) round($sim * 100);
						$excerpt        = !empty($target_post->post_excerpt) ? $target_post->post_excerpt : wp_trim_words($target_post->post_content, 20);

						$suggestions[] = array(
							'id'             => $t_id,
							'title'          => html_entity_decode(get_the_title($t_id), ENT_QUOTES, 'UTF-8'),
							'url'            => get_permalink($t_id),
							'post_type'      => $target_post->post_type,
							'similarity'     => $sim,
							'similarity_pct' => $similarity_pct,
							'excerpt'        => wp_strip_all_tags($excerpt),
							'is_precomputed' => false,
						);
					}
				}
			}
		}

		return rest_ensure_response(array(
			'success'     => true,
			'suggestions' => $suggestions,
			'count'       => count($suggestions),
		));
	}

	/**
	 * Find natural anchor phrase opportunities for a target post within source text.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response Response object.
	 */
	public function find_anchors($request) {
		$source_content = (string) $request->get_param('source_content');
		$target_post_id = absint($request->get_param('target_post_id'));
		$anchor_text    = sanitize_text_field((string) $request->get_param('anchor_text'));
		$limit          = max(1, min(5, (int) $request->get_param('limit')));

		if (empty($source_content)) {
			return new WP_Error(
				'empty_content',
				__('No source content provided for anchor extraction.', 'ai-post-scheduler'),
				array('status' => 400)
			);
		}

		if (!$target_post_id) {
			return new WP_Error(
				'invalid_target',
				__('Invalid target post ID.', 'ai-post-scheduler'),
				array('status' => 400)
			);
		}

		$result = $this->inserter_service->find_insertion_locations_for_text(
			$source_content,
			$target_post_id,
			$anchor_text,
			$limit
		);

		if (is_wp_error($result)) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array('status' => 500)
			);
		}

		return rest_ensure_response(array(
			'success' => true,
			'data'    => $result,
		));
	}
}
