#!/usr/bin/env bash
# =============================================================================
# qa-seed.sh — load real data into a QA build's database.
#
#   # register (or refresh) the cached production dump, then seed a build
#   ./scripts/qa-seed.sh --prs 1887,1888 --file ~/Downloads/devstacktips.sql
#
#   # re-seed from the already-cached dump
#   ./scripts/qa-seed.sh --prs 1887,1888
#
#   # copy another running stack's database instead of the dump
#   ./scripts/qa-seed.sh --prs 1887,1888 --from-build dev
#
# The dump you export from production is cached once at .qa-builds/_seed/prod.sql
# and reused by every later build, so you only re-export when you want fresher
# data.
#
# After importing, the script repairs everything that makes a production dump
# unusable locally:
#   * detects the dump's table prefix and points wp-config.php at it
#   * reads the dump's own siteurl and rewrites it to this build's port,
#     serialization-safe, via wp search-replace
#   * guarantees a local administrator you can actually log in as
#   * activates the plugin so its migrations run against the real data
#
# OPTIONS
#   --prs <list>        PR ids identifying the build (or --build <key>)
#   --build <key>       Build key, e.g. 1887-1888
#   --file <path>       Register this .sql/.sql.gz as the cached seed, then use it
#   --from-build <key>  Clone from another build's database ('dev' = main stack)
#   --old-url <url>     Override the source URL instead of auto-detecting it
#   --no-admin          Skip creating the local admin user
#   -h, --help          Show this help
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/qa-lib.sh
source "$SCRIPT_DIR/qa-lib.sh"

: "${QA_ADMIN_USER:=admin}"
: "${QA_ADMIN_PASS:=admin}"
: "${QA_ADMIN_EMAIL:=admin@example.com}"

PRS_INPUT=""
BUILD_KEY=""
SEED_INPUT=""
FROM_BUILD=""
OLD_URL=""
MAKE_ADMIN=1

usage() { sed -n '2,/^# ====/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//;$d'; }

parse_args() {
	while [[ $# -gt 0 ]]; do
		case "$1" in
		--prs) PRS_INPUT="$PRS_INPUT $2" && shift 2 ;;
		--prs=*) PRS_INPUT="$PRS_INPUT ${1#*=}" && shift ;;
		--build) BUILD_KEY="$2" && shift 2 ;;
		--build=*) BUILD_KEY="${1#*=}" && shift ;;
		--file) SEED_INPUT="$2" && shift 2 ;;
		--file=*) SEED_INPUT="${1#*=}" && shift ;;
		--from-build) FROM_BUILD="$2" && shift 2 ;;
		--from-build=*) FROM_BUILD="${1#*=}" && shift ;;
		--old-url) OLD_URL="$2" && shift 2 ;;
		--old-url=*) OLD_URL="${1#*=}" && shift ;;
		--no-admin) MAKE_ADMIN=0 && shift ;;
		-h | --help)
			usage
			exit 0
			;;
		*) qa_die "unknown option: $1" ;;
		esac
	done
}

# -----------------------------------------------------------------------------
# Seed registration
#
# A dump taken with mysqldump --databases (or from some hosting panels) carries
# CREATE DATABASE / USE statements that would redirect the import away from the
# container's `wordpress` database. Those two statement types are the only ones
# that need removing, so we strip them while caching and say so.
# -----------------------------------------------------------------------------
register_seed() {
	local input="$1"
	local dest stripped

	[[ -f "$input" ]] || qa_die "dump file not found: $input"

	dest="$(qa_seed_file)"
	mkdir -p "$(qa_seed_dir)"

	qa_info "Caching production dump -> ${QA_DIR_NAME}/_seed/prod.sql"

	if [[ "$input" == *.gz ]]; then
		gunzip -c "$input" >"${dest}.raw"
	else
		cp "$input" "${dest}.raw"
	fi

	stripped="$(grep -c -E '^[[:space:]]*(CREATE DATABASE|USE )' "${dest}.raw" || true)"
	grep -v -E '^[[:space:]]*(CREATE DATABASE|USE )' "${dest}.raw" >"$dest"
	rm -f "${dest}.raw"

	if [[ "${stripped:-0}" -gt 0 ]]; then
		qa_warn "removed $stripped CREATE DATABASE/USE statement(s) so the import lands in the container's 'wordpress' database"
	fi

	printf 'QA_SEED_SOURCE=%s\nQA_SEED_CACHED=%s\n' \
		"$input" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >"$(qa_seed_dir)/seed.env"

	qa_ok "seed cached ($(du -h "$dest" | cut -f1))"
}

# -----------------------------------------------------------------------------
# Import
# -----------------------------------------------------------------------------

import_sql_file() {
	local file="$1"
	qa_info "Resetting the build database..."
	qa_wp db reset --yes >/dev/null

	qa_info "Importing $(du -h "$file" | cut -f1) of SQL (this can take a while)..."
	qa_compose exec -T db mysql -u wordpress -pwordpress wordpress <"$file" ||
		qa_die "import failed — check that the dump is plain SQL for a single database"
	qa_ok "import complete"
}

# The dump's prefix rarely matches wp-config.php's default of wp_. Find the
# options table it actually shipped and repoint the config at it.
repair_table_prefix() {
	local table prefix current

	table="$(qa_compose exec -T db mysql -N -B -u wordpress -pwordpress \
		-e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='wordpress' AND TABLE_NAME LIKE '%options' ORDER BY CHAR_LENGTH(TABLE_NAME) LIMIT 1;" 2>/dev/null | tr -d '\r')"

	[[ -n "$table" ]] || qa_die "no *options table found after import — the dump does not look like a WordPress database"

	prefix="${table%options}"
	current="$(qa_wp config get table_prefix 2>/dev/null | tr -d '\r')"

	if [[ "$prefix" != "$current" ]]; then
		qa_warn "dump uses table prefix '${prefix}' (wp-config had '${current}') — updating wp-config.php"
		qa_wp config set table_prefix "$prefix" >/dev/null
	fi
	qa_ok "table prefix: ${prefix}"
}

rewrite_urls() {
	local new_url="$1"
	local old_url="$OLD_URL"

	if [[ -z "$old_url" ]]; then
		old_url="$(qa_wp option get siteurl 2>/dev/null | tr -d '\r')"
		[[ -n "$old_url" ]] || qa_die "could not read siteurl from the imported database — pass --old-url"
		qa_ok "detected source site URL: ${old_url}"
	fi

	if [[ "$old_url" == "$new_url" ]]; then
		qa_ok "site URL already ${new_url}"
		return 0
	fi

	qa_info "Rewriting ${old_url} -> ${new_url}"
	qa_wp option update home "$new_url" >/dev/null
	qa_wp option update siteurl "$new_url" >/dev/null
	qa_wp search-replace "$old_url" "$new_url" \
		--all-tables --skip-columns=guid --report-changed-only ||
		qa_warn "search-replace reported problems — check the output above"

	# Bare-host form catches protocol-relative and http/https mismatches.
	local old_host new_host
	old_host="${old_url#*://}"
	new_host="${new_url#*://}"
	if [[ "$old_host" != "$new_host" ]]; then
		qa_wp search-replace "$old_host" "$new_host" \
			--all-tables --skip-columns=guid --report-changed-only >/dev/null || true
	fi
}

# Production user passwords are unknown locally, so guarantee a way in.
ensure_local_admin() {
	qa_info "Ensuring local administrator '${QA_ADMIN_USER}'..."

	if qa_wp user get "$QA_ADMIN_USER" --field=ID >/dev/null 2>&1; then
		qa_wp user update "$QA_ADMIN_USER" \
			--user_pass="$QA_ADMIN_PASS" --role=administrator >/dev/null
		qa_ok "reset password for existing user '${QA_ADMIN_USER}'"
	else
		local email="$QA_ADMIN_EMAIL"
		if ! qa_wp user create "$QA_ADMIN_USER" "$email" \
			--role=administrator --user_pass="$QA_ADMIN_PASS" >/dev/null 2>&1; then
			email="qa-${QA_KEY}@example.test"
			qa_wp user create "$QA_ADMIN_USER" "$email" \
				--role=administrator --user_pass="$QA_ADMIN_PASS" >/dev/null ||
				qa_die "could not create a local administrator"
		fi
		qa_ok "created '${QA_ADMIN_USER}' (${email})"
	fi
}

activate_plugin() {
	qa_info "Activating ai-post-scheduler so its migrations run against the real data..."
	if qa_wp_full plugin activate ai-post-scheduler 2>&1 | sed 's/^/    /'; then
		qa_ok "plugin active"
	else
		qa_warn "plugin activation reported an error — see output above"
	fi

	# Any plugin the dump had active but that is not installed here will simply
	# be inert; surfacing them avoids confusion when a QA page looks wrong.
	local missing
	missing="$(qa_wp_full plugin list --status=active --field=name 2>/dev/null |
		tr -d '\r' | while read -r p; do
		[[ -n "$p" ]] || continue
		qa_wp_full plugin is-installed "$p" >/dev/null 2>&1 || printf '%s ' "$p"
	done)"
	[[ -z "${missing// /}" ]] || qa_warn "active in the dump but not installed locally: ${missing}"
}

clone_from_build() {
	local source="$1"
	local source_project tmp

	if [[ "$source" == "dev" ]]; then
		source_project="$(basename "$(qa_repo_root)")"
	else
		source_project="$(qa_project_from_key "$(qa_key_from_prs "$(qa_normalize_prs "$source")")")"
	fi

	local source_db="${source_project}-db"
	docker inspect "$source_db" >/dev/null 2>&1 ||
		qa_die "source database container '${source_db}' is not running"

	tmp="$(mktemp)"
	qa_info "Dumping ${source_db}..."
	docker exec -i "$source_db" mysqldump -u wordpress -pwordpress \
		--single-transaction --quick wordpress >"$tmp" 2>/dev/null ||
		qa_die "failed to dump ${source_db}"

	import_sql_file "$tmp"
	rm -f "$tmp"
}

main() {
	parse_args "$@"

	cd "$(qa_repo_root)"
	qa_require_docker

	if [[ -n "$SEED_INPUT" ]]; then
		register_seed "$SEED_INPUT"
	fi

	if [[ -z "$BUILD_KEY" ]]; then
		[[ -n "${PRS_INPUT// /}" ]] || {
			# Registering a seed without a target build is a valid standalone action.
			[[ -n "$SEED_INPUT" ]] && exit 0
			usage
			qa_die "no build selected (--prs or --build)"
		}
		BUILD_KEY="$(qa_key_from_prs "$(qa_normalize_prs "$PRS_INPUT")")"
	fi

	qa_load_state "$BUILD_KEY"

	qa_compose ps -q db >/dev/null 2>&1 && [[ -n "$(qa_compose ps -q db)" ]] ||
		qa_die "build '${BUILD_KEY}' is not running — start it with: make qa-up PRS=${QA_PRS}"

	qa_wait_for_db

	if [[ -n "$FROM_BUILD" ]]; then
		clone_from_build "$FROM_BUILD"
	else
		local seed
		seed="$(qa_seed_file)"
		[[ -f "$seed" ]] ||
			qa_die "no cached production dump. Export one from DevStackTips and register it: make qa-seed PRS=${QA_PRS} FILE=/path/to/dump.sql"
		import_sql_file "$seed"
	fi

	repair_table_prefix
	rewrite_urls "http://localhost:${QA_WP_PORT}"
	[[ "$MAKE_ADMIN" -eq 1 ]] && ensure_local_admin
	activate_plugin

	qa_wp cache flush >/dev/null 2>&1 || true
	qa_wp rewrite flush >/dev/null 2>&1 || true

	echo
	qa_ok "Build ${QA_BRANCH} is running on real data"
	printf '  site   http://localhost:%s/wp-admin  (%s / %s)\n' \
		"$QA_WP_PORT" "$QA_ADMIN_USER" "$QA_ADMIN_PASS"
}

main "$@"
