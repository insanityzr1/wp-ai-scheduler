To break down `generate_post_from_context`, I will extract 3 helper methods inside `AIPS_Generator`:

1.  `private function initialize_history_for_generation($context)`
    -   Lines 1075-1103. Returns the initialized history. Wait, `this->current_history` is a class property.
    -   Or `private function setup_generation_history($context)`

2.  `private function generate_post_metadata($context, $content, &$component_statuses)`
    -   Lines 1186-1268. Returns array with `title`, `excerpt`, `resolved_image_prompt` (and updates `$component_statuses`).

3.  `private function handle_post_creation_and_status($context, $content, $metadata, $component_statuses, $generation_start)`
    -   Lines 1269-1463. Includes post creation, featured image, downgrades, logging, and history completion. Returns `$post_id`.
