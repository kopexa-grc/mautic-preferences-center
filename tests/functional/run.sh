#!/usr/bin/env bash
# Functional test: the preferences center in a real Mautic 7.
#   tests/functional/run.sh    (see tests/functional/lib.sh for the environment)
# shellcheck source=tests/functional/lib.sh
source "$(dirname "$0")/lib.sh"
HERE="$(cd "$(dirname "$0")" && pwd)"

db() { # <sql>: prints the rows tab-separated
  php -r '
    $pdo = new PDO(sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_NAME")), getenv("DB_USER"), getenv("DB_PASSWORD"));
    foreach ($pdo->query($argv[1])->fetchAll(PDO::FETCH_NUM) as $row) { echo implode("\t", $row), "\n"; }
  ' "$1"
}

install_mautic
cp -r "$HERE/fixtures/prefs-test" "$MAUTIC_DIR/docroot/themes/prefs-test"
# Webhooks go to a local receiver; Mautic refuses private addresses unless allowed.
set_params '{"webhook_allowed_private_addresses": ["127.0.0.1"], "queue_mode": "immediate_process"}'
start_server

# A stand-in for another system: records webhook requests.
HOOK_PORT=$((PORT + 19))
cat >"$WORK/receiver.php" <<'PHP'
<?php
file_put_contents(getenv('HOOK_LOG'), json_encode(['signature' => $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? null, 'body' => file_get_contents('php://input')])."\n", FILE_APPEND);
echo 'ok';
PHP
(HOOK_LOG="$WORK/hooks.jsonl" php -S "127.0.0.1:$HOOK_PORT" "$WORK/receiver.php" >"$WORK/receiver.log" 2>&1 &)
for _ in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:$HOOK_PORT/" && break; sleep 0.5; done
: >"$WORK/hooks.jsonl"
# hooks: the webhook messages of our event, one JSON object per line
hooks() { jq -c '.body | fromjson | .["kopexa.preferences_center.preferences_changed"][]?' "$WORK/hooks.jsonl"; }

echo "Setup as code (preferences-center:sync)"
internal="$(api -X POST "$BASE/api/categories/new" -d title=Internal -d bundle=global -d isPublished=1 | jq -r .category.id)"
declaration='{
  "topics": [
    {"name": "Webinars", "text": "Invitations to online events"},
    {"name": "Hidden topic", "visible": false},
    {"name": "Product updates", "text": "New features, once a month"}
  ],
  "hideOtherTopics": true,
  "page": {"alias": "preferences", "title": "Email preferences", "theme": "prefs-test"}
}'
out="$(console preferences-center:sync --json "$declaration" 2>&1)" || { echo "$out"; fail "sync"; }
grep -q 'topic "Webinars": created' <<<"$out" && grep -q 'category "Internal": hidden on the page' <<<"$out" \
  && grep -q 'page "preferences": created' <<<"$out" && grep -q 'settings: .*email_default_preference_center_id' <<<"$out" \
  && pass "topics, page and settings created; the other category hidden" || { echo "$out"; fail "sync output"; }
out="$(console preferences-center:sync --json "$declaration" 2>&1)"
[[ "$(grep -c 'unchanged' <<<"$out")" == 5 ]] && pass "second run changes nothing" || { echo "$out"; fail "sync not idempotent"; }
out="$(console preferences-center:sync --json '{"topics":[{"name":""}],"page":{"theme":"blank"},"color":1}' 2>&1)" && fail "invalid declaration accepted"
grep -q 'unknown key "color"' <<<"$out" && grep -q 'topic 1: "name" is required' <<<"$out" && grep -q 'not a preference center theme' <<<"$out" \
  && pass "invalid declarations are refused with every error" || { echo "$out"; fail "validation"; }
id_of() { db "SELECT id FROM categories WHERE title = '$1'"; }
webinars="$(id_of Webinars)"; hidden="$(id_of 'Hidden topic')"; updates="$(id_of 'Product updates')"

echo "Preference page"
cid="$(api -X POST "$BASE/api/contacts/new" -d email=prefs@example.com | jq -r .contact.id)"
eid="$(api -X POST "$BASE/api/emails/new" -d name=Newsletter -d subject=Newsletter -d emailType=template -d isPublished=1 -d "category=$webinars" \
  --data-urlencode 'customHtml=<html><body><a href="{unsubscribe_url}">Unsubscribe</a></body></html>' | jq -r .email.id)"
api -X POST "$BASE/api/emails/$eid/contact/$cid/send" >/dev/null
hash="$(db "SELECT tracking_hash FROM email_stats WHERE lead_id = $cid ORDER BY id DESC LIMIT 1")"
[[ -n "$hash" ]] || fail "no email sent"
page() { curl -s -c "$JAR" -b "$JAR" "$BASE/email/unsubscribe/$hash" -o "$WORK/page.html"; }
page
# the topics as shown, top to bottom
shown() { php -r 'preg_match_all("#<span class=\"pref-topic-name\">([^<]*)</span>#", file_get_contents($argv[1]), $m); echo implode(",", array_map("html_entity_decode", $m[1]));' "$WORK/page.html"; }
[[ "$(shown)" == "Webinars,Product updates" ]] && pass "only shown topics, in the declared order" || { echo "shown: $(shown)"; fail "topics on the page"; }
grep -q '<span class="pref-topic-text">Invitations to online events</span>' "$WORK/page.html" && pass "text under the topic name" || fail "topic text"

echo "Saving keeps hidden topics"
webhook="$(api -X POST "$BASE/api/hooks/new" -H 'Content-Type: application/json' \
  -d "{\"name\":\"Preferences\",\"webhookUrl\":\"http://127.0.0.1:$HOOK_PORT/\",\"secret\":\"hook-secret\",\"isPublished\":true,\"triggers\":[\"kopexa.preferences_center.preferences_changed\"]}")"
jq -e '.hook.triggers == ["kopexa.preferences_center.preferences_changed"]' <<<"$webhook" >/dev/null \
  && pass "webhook event available in Mautic's webhooks" || { echo "$webhook"; fail "webhook with our event"; }
save() { # <category IDs to keep switched on…>
  local token args=()
  token="$(perl -0ne 'print $1 if /<input\b[^>]*name="lead_contact_frequency_rules\[_token\]"[^>]*>/ and $& =~ /\bvalue="([^"]*)"/' "$WORK/page.html")"
  for c in "$@"; do args+=(--data-urlencode "lead_contact_frequency_rules[global_categories][]=$c"); done
  curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$BASE/email/unsubscribe/$hash" \
    --data-urlencode "lead_contact_frequency_rules[_token]=$token" \
    --data-urlencode "lead_contact_frequency_rules[lead_channels][subscribed_channels][0]=email" "${args[@]}"
}
removed() { db "SELECT COALESCE(MAX(manually_removed), 0) FROM lead_categories WHERE lead_id = $cid AND category_id = $1"; }
[[ "$(save "$updates")" == 302 ]] && [[ "$(removed "$webinars")" == 1 ]] && [[ "$(removed "$updates")" == 0 ]] \
  && [[ "$(removed "$hidden")" == 0 ]] && [[ "$(removed "$internal")" == 0 ]] \
  && pass "Webinars switched off; the hidden topics the contact never saw stay on" \
  || { db "SELECT category_id, manually_added, manually_removed FROM lead_categories WHERE lead_id = $cid"; fail "saving preferences"; }
sleep 1
msg="$(hooks | tail -1)"
jq -e --argjson w "$webinars" --argjson c "$cid" '.contact.id == $c and .contact.email == "prefs@example.com" and .source == "preference_center"
    and .changes.topics == [{"id": $w, "name": "Webinars", "subscribed": false}] and (.changes | has("emails") | not)
    and .current.emails == true and ([.current.topics_unsubscribed[].name] == ["Webinars"])' <<<"$msg" >/dev/null \
  && pass "webhook: the change (Webinars off), its source and the current state" || { cat "$WORK/hooks.jsonl"; fail "webhook message"; }
line="$(tail -1 "$WORK/hooks.jsonl")"
[[ "$(jq -r .body <<<"$line" | openssl dgst -sha256 -hmac hook-secret -binary | base64)" == "$(jq -r .signature <<<"$line")" ]] \
  && pass "signed with the webhook secret (Webhook-Signature: HMAC-SHA256)" || fail "webhook signature"
count="$(hooks | wc -l)"; page; save "$updates" >/dev/null; sleep 1
[[ "$(hooks | wc -l)" == "$count" ]] && pass "saving without a change sends nothing" || { hooks | tail -1; fail "webhook without a change"; }

echo "Admin: topic settings in the category form"
login >/dev/null
ajax_csrf="$(curl -s -b "$JAR" -c "$JAR" "$BASE/s/categories" | sed -nE "s/.*mauticAjaxCsrf *= *'([^']+)'.*/\1/p" | head -1)"
curl -s -b "$JAR" -c "$JAR" -H 'X-Requested-With: XMLHttpRequest' "$BASE/s/categories/category/edit/$hidden" | jq -r .newContent >"$WORK/form.html"
grep -q 'name="category_form\[preferencesCenter\]\[visible\]"' "$WORK/form.html" && grep -q 'name="category_form\[preferencesCenter\]\[text\]"' "$WORK/form.html" \
  && pass "fields Show as topic, Position, Text in the category form" || fail "category form fields"
form_token="$(perl -0ne 'print $1 if /<input\b[^>]*name="category_form\[_token\]"[^>]*>/ and $& =~ /\bvalue="([^"]*)"/' "$WORK/form.html")"
curl -s -o /dev/null -b "$JAR" -c "$JAR" -H 'X-Requested-With: XMLHttpRequest' -H "X-CSRF-Token: $ajax_csrf" -X POST "$BASE/s/categories/category/edit/$hidden" \
  --data-urlencode "category_form[_token]=$form_token" --data-urlencode "category_form[title]=Hidden topic" --data-urlencode "category_form[isPublished]=1" \
  --data-urlencode "category_form[preferencesCenter][visible]=1" --data-urlencode "category_form[preferencesCenter][position]=0" \
  --data-urlencode "category_form[preferencesCenter][text]=Now visible" --data-urlencode "category_form[buttons][save]="
page
[[ "$(shown)" == "Hidden topic,Webinars,Product updates" ]] && grep -q 'Now visible' "$WORK/page.html" \
  && pass "saved in the form: shown first, with its text" || { echo "shown: $(shown)"; fail "category form save"; }

echo "API: categories with preferencesCenter"
api -X PATCH "$BASE/api/categories/$hidden/edit" -H 'Content-Type: application/json' -d '{"description": "only the description"}' >/dev/null
page
[[ "$(shown)" == "Hidden topic,Webinars,Product updates" ]] && pass "API edits without the field leave the topic settings alone" || fail "API without field"
api -X PATCH "$BASE/api/categories/$hidden/edit" -H 'Content-Type: application/json' -d '{"preferencesCenter": {"visible": false}}' >/dev/null
page
[[ "$(shown)" == "Webinars,Product updates" ]] && pass "API: preferencesCenter.visible false hides the topic" || { echo "shown: $(shown)"; fail "API hide"; }

echo "Unsubscribe from all and re-subscribe"
curl -s -o /dev/null -X POST "$BASE/email/dnc/$hash" -d 'List-Unsubscribe=One-Click'; sleep 1
hooks | tail -1 | jq -e '.source == "one_click_unsubscribe" and .changes.emails == {"subscribed": false, "reason": "unsubscribed"} and .current.emails == false' >/dev/null \
  && pass "webhook on one-click unsubscribe (List-Unsubscribe-Post)" || { hooks | tail -1; fail "one-click webhook"; }
curl -s -o /dev/null "$BASE/email/resubscribe/$hash"; sleep 1
hooks | tail -1 | jq -e '.source == "resubscribe" and .changes.emails.subscribed == true and .current.emails == true' >/dev/null \
  && pass "webhook on re-subscribe" || { hooks | tail -1; fail "re-subscribe webhook"; }
