#!/bin/sh
# Posts with links of every kind: fine, missing, redirecting, blocked, timing out.
cd "$(dirname "$0")" || exit 1
# Same compose project as up.sh (LSN_PROJECT, else COMPOSE_PROJECT_NAME, else "docker"); see the note there.
LSN_PROJECT="${LSN_PROJECT:-${COMPOSE_PROJECT_NAME:-docker}}"
wp() { docker compose -p "$LSN_PROJECT" run --rm -T cli "$@"; }
existing=$(wp post list --post_type=page --name=about --field=ID 2>/dev/null | head -1)
[ -z "$existing" ] && wp post create --post_type=page --post_status=publish --post_title="About" --post_name=about --post_content="<p>About us.</p>" >/dev/null
wp post create --post_status=publish --post_title="Links of every kind" --post_content='<!-- wp:paragraph --><p>Fine: <a href="https://example.com/">example</a> and <a href="/about/">about</a>. Missing internal: <a href="/no-such-page-xyz/">gone</a>. Missing external: <a href="https://github.com/hxckya/this-repo-does-not-exist-404">404</a>. Redirect: <a href="http://github.com/hxckya">github</a>. Blocked: <a href="https://www.linkedin.com/in/someone">linkedin</a>. Timeout: <a href="https://10.255.255.1/">dead host</a>. Skipped: <a href="mailto:x@example.com">mail</a> <a href="#top">top</a> <a href="tel:123">tel</a>.</p><!-- /wp:paragraph --><!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/missing-image.png" alt=""/></figure><!-- /wp:image -->' >/dev/null
wp post create --post_status=publish --post_title="Second post, same broken link" --post_content='<p>Also links to <a href="https://github.com/hxckya/this-repo-does-not-exist-404">the missing repo</a> and <a href="https://example.com/">example</a>.</p>' >/dev/null
wp post create --post_status=draft --post_title="Draft with a bad link" --post_content='<p><a href="https://example.com/draft-only-404-xyz">draft link</a></p>' >/dev/null
echo seeded
