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
[[ "$(printf '%s' "$(jq -r .body <<<"$line")" | openssl dgst -sha256 -hmac hook-secret -binary | base64)" == "$(jq -r .signature <<<"$line")" ]] \
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

echo "Link request: /email/preferences"
SMTP_PORT=$((PORT + 21)); mkdir -p "$WORK/mails"
(php "$HERE/smtp-sink.php" "$SMTP_PORT" "$WORK/mails" >"$WORK/smtp.log" 2>&1 &)
set_params "{\"mailer_dsn\": \"smtp://127.0.0.1:$SMTP_PORT\", \"messenger_dsn_email\": \"sync://\", \"mailer_from_email\": \"news@example.com\", \"mailer_from_name\": \"Test\"}"
out="$(console preferences-center:sync --json '{"topics":[{"name":"Webinars"}],"linkRequest":{"locale":"de","buttonColor":"#006CB8"}}' 2>&1)" || { echo "$out"; fail "sync linkRequest"; }
grep -q 'preferences_center_locale' <<<"$out" && pass "linkRequest settings written by the sync" || { echo "$out"; fail "sync linkRequest settings"; }
out="$(console preferences-center:sync --json '{"linkRequest":{"email":"No such email","buttonColor":"blue","colour":1}}' 2>&1)" && fail "invalid linkRequest accepted"
grep -q 'linkRequest.email: no email "No such email"' <<<"$out" && grep -q 'linkRequest.buttonColor' <<<"$out" && grep -q 'linkRequest: unknown key "colour"' <<<"$out" \
  && pass "invalid linkRequest refused" || { echo "$out"; fail "linkRequest validation"; }
LR_JAR="$WORK/lr-jar"
request_link() { # <address> [token]: posts the form like a browser, prints the HTTP status
  local token
  curl -s -c "$LR_JAR" -b "$LR_JAR" "$BASE/email/preferences" -o "$WORK/lr.html"
  token="$(sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$WORK/lr.html")"
  curl -s -o "$WORK/lr-post.html" -w '%{http_code}' -c "$LR_JAR" -b "$LR_JAR" -X POST "$BASE/email/preferences" \
    --data-urlencode "email=$1" --data-urlencode "_token=${2-$token}"
}
mails() { find "$WORK/mails" -name '*.eml' | wc -l; }
[[ "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/preferences")" == "302 $BASE/email/preferences" ]] \
  && pass "the preference page without a personal link redirects to the link request" || fail "page redirect"
curl -s "$BASE/email/preferences" | grep -q 'Gib deine E-Mail-Adresse ein' && pass "form in the configured language (de)" || fail "link request form"
[[ "$(request_link nobody@example.com)" == 200 ]] && grep -q 'Schau in dein Postfach' "$WORK/lr-post.html" && sleep 1 && [[ "$(mails)" == 0 ]] \
  && pass "unknown address: same answer, no email" || fail "unknown address"
[[ "$(request_link PREFS@example.com)" == 200 ]] && grep -q 'Schau in dein Postfach' "$WORK/lr-post.html" && sleep 1 && [[ "$(mails)" == 1 ]] \
  && pass "known address (any case): same answer, one email" || { cat "$WORK/smtp.log"; fail "known address"; }
mail="$(find "$WORK/mails" -name '*.eml' | sort | head -1)"
read -r subject link < <(python3 - "$mail" <<'PY'
import email, re, sys
from email.header import decode_header, make_header
m = email.message_from_binary_file(open(sys.argv[1], 'rb'))
html = ''.join(p.get_payload(decode=True).decode() for p in m.walk() if p.get_content_type() == 'text/html')
links = re.findall(r'href="(https?://[^"]*/email/unsubscribe/[^"]+)"', html)
print(str(make_header(decode_header(m['Subject']))).replace(' ', '_'), links[0] if links else '')
PY
)
grep -qi '^To: .*prefs@example.com' "$mail" && [[ "$subject" == "Dein_Link_zu_deinen_E-Mail-Einstellungen" ]] && [[ -n "$link" ]] \
  && pass "email to the contact with a personal link (subject in German)" || { echo "subject=$subject link=$link"; head -c 1500 "$mail"; fail "link email"; }
curl -s "$link" -o "$WORK/via-link.html"
grep -q 'pref-topic-name">Webinars<' "$WORK/via-link.html" \
  && pass "the link opens the preference page of that contact" || { head -c 800 "$WORK/via-link.html"; fail "link opens page"; }
[[ "$(db "SELECT COUNT(*) FROM emails WHERE name = 'Preferences center: link' AND is_published = 1")" == 1 ]] \
  && pass "sent as the Mautic email \"Preferences center: link\" (editable in Mautic)" || fail "link email entity"
request_link prefs@example.com >/dev/null; request_link prefs@example.com >/dev/null
[[ "$(request_link prefs@example.com)" == 429 ]] && grep -q 'Zu viele Anfragen' "$WORK/lr-post.html" \
  && pass "at most 3 requests per address and hour" || fail "rate limit"
[[ "$(request_link other@example.com forged)" == 400 ]] && pass "form without a valid token refused" || fail "csrf"

echo "Links of test emails (signed with the address, no statistics)"
secret="$(php -r 'include $argv[1]."/config/local.php"; echo $parameters["secret_key"];' "$MAUTIC_DIR")"
sig="$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' prefs@example.com "$secret")"
read -r code location < <(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/email/unsubscribe/example/prefs@example.com/$sig")
[[ "$code" == 302 && "$location" == "$BASE/email/unsubscribe/"* ]] && curl -s "$location" | grep -q 'pref-topic-name">Webinars<' \
  && pass "a test email's link opens the preference page (not Mautic's plain form)" || { echo "$code $location"; fail "signed link"; }
[[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/email/unsubscribe/example/prefs@example.com/${sig%?}0")" != 302 ]] \
  && pass "a wrong signature is left to Mautic" || fail "wrong signature redirected"
