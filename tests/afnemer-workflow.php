<?php
require __DIR__ . '/afnemer-fixture.php';
$store[BZV_Afnemer_Reviews::SETTINGS] = ['enabled' => true, 'emailEnabled' => true, 'recipientMode' => 'administrators', 'browserKey' => 'browser-fixture', 'serverKey' => 'server-fixture'];
BZV_Afnemer_Reviews::routes();
foreach ($routes as $path => $route) check($route['methods'] === ($path === '/afnemers/recipient-options' ? 'GET' : 'POST') && $route['permission_callback'] === [BZV_Afnemer_Reviews::class, 'permission'], 'Every mutation requires POST; every private route requires admin capability.');
BZV_Afnemer_Reviews::sync();
check(count($mails) === 2, 'All current WordPress administrators receive one notification.');
check(!str_contains($mails[0]['html'], 'bop_post_v1') && str_contains($mails[0]['html'], 'Locatie controleren'), 'Mail contains a review link, no credentials.');
check(!str_contains($mails[0]['html'], 'Voorbeeld & Zonen') && str_contains($mails[0]['html'], 'Voorbeeld &amp; Zonen'), 'Customer names are escaped in mail.');
check(BZV_Bop_Locations::looper() === [], 'Private candidates are absent from the public Pro provider.');
$remoteMode = 'unchanged'; BZV_Afnemer_Reviews::sync(); check(count($mails) === 2, 'An unchanged snapshot does not send again.');
$r = reset($store[BZV_Afnemer_Reviews::STATE]['records']); $id = $r['id'];
$cap = false;
check(is_wp_error(BZV_Afnemer_Reviews::handle('lookup', ['id' => $id, 'revision' => $r['revision']])), 'Anonymous or editor requests cannot look up private customers.');
$cap = true;
$lookup = BZV_Afnemer_Reviews::handle('lookup', ['id' => $id, 'revision' => $r['revision'], 'placeId' => 'test-place']);
check(count($lookup['items']) === 1 && count($lookup['items'][0]['addresses']) === 1, 'Selected Google point gets independent PDOK address proposals.');
check(!str_contains(json_encode($lookup), 'DO NOT COPY') && !str_contains(json_encode($transients), 'DO NOT COPY'), 'Google-formatted address is neither stored nor returned.');
$body = ['id' => $id, 'revision' => $r['revision'], 'fields' => ['name' => '<script>test</script>', ...$lookup['items'][0]['addresses'][0]],
    'token' => $lookup['items'][0]['token'], 'approve' => true, 'confirmed' => false];
check(is_wp_error(BZV_Afnemer_Reviews::handle('save', $body)), 'No approval without explicit confirmation.');
$body['confirmed'] = true; $bad = $body; $bad['fields']['address'] = 'Changed after lookup';
check(is_wp_error(BZV_Afnemer_Reviews::handle('save', $bad)), 'Lookup is bound to the proposed/queried address.');
$user = 18; check(is_wp_error(BZV_Afnemer_Reviews::handle('save', $body)), 'A different administrator cannot replay a lookup token.'); $user = 17;
check(!is_wp_error(BZV_Afnemer_Reviews::handle('save', $body)), 'Reviewed proposal can be approved.');
check(count(BZV_Bop_Locations::looper()) === 1 && !str_contains(BZV_Bop_Locations::address_list(), '<script>'), 'Approved record reaches escaped Pro map/list.');
check(is_wp_error(BZV_Afnemer_Reviews::handle('save', $body)), 'Stale repeated approval cannot overwrite later edits.');
$remoteMode = 'fail'; BZV_Afnemer_Reviews::sync();
check(count(BZV_Bop_Locations::looper()) === 1, 'Temporary transport error preserves a still-fresh reviewed location.');
check(!str_contains(json_encode($store), 'raw secret'), 'Raw upstream exceptions are never retained.');
$store[BZV_Afnemer_Reviews::STATE]['directory']['publishedTimestamp'] = time() - BZV_Afnemer_State::MAX_AGE;
check(BZV_Bop_Locations::looper() === [], 'Read path itself refuses stale activity even if cron has not run.');
$remoteMode = 'fresh'; BZV_Afnemer_Reviews::sync(); // contradictory same version correctly rejected
// Browser preview uses real PHP rendering and synthetic customer data, never production mail.
if (getenv('BZV_PREVIEW_DIR')) {
    $store[BZV_Afnemer_Reviews::STATE]['directory']['publishedTimestamp'] = time();
    $store[BZV_Afnemer_Reviews::STATE]['records'][$id]['name'] = 'Voorbeeld & Zonen';
    $store[BZV_Afnemer_Reviews::STATE]['records'][$id]['approvedAt'] = null;
    $store[BZV_Afnemer_Reviews::STATE]['records'][$id]['latitude'] = null;
    $store[BZV_Afnemer_Reviews::STATE]['records'][$id]['longitude'] = null;
    $preview = getenv('BZV_PREVIEW_DIR');
    $_GET = ['review' => $id]; ob_start(); BZV_Afnemer_Admin::render(); $html = ob_get_clean();
    file_put_contents($preview . '/review.html', '<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="afnemer-review.css"><body style="margin:24px;background:#f4f5f6;font-family:system-ui">' . $html . '</body></html>');
    file_put_contents($preview . '/mail.html', $mails[0]['html']);
}
// A transport refusal retries only when due; an interrupted handoff needs deliberate retry.
$store[BZV_Afnemer_Reviews::STATE] = BZV_Afnemer_State::empty();
$mails = []; $mailAccepted = false; $remoteMode = 'fresh';
BZV_Afnemer_Reviews::sync();
check(count($mails) === 2, 'Each administrator was offered the first mail once.');
BZV_Afnemer_Reviews::sync();
check(count($mails) === 2, 'A mail failure is not retried immediately.');
foreach ($store[BZV_Afnemer_Reviews::STATE]['jobs'] as &$job) { $job['nextAttempt'] = time() - 1; } unset($job);
$mailAccepted = true; BZV_Afnemer_Reviews::sync();
check(count($mails) === 4, 'Due failed mails retry and can be accepted.');
$jobId = array_key_first($store[BZV_Afnemer_Reviews::STATE]['jobs']);
$store[BZV_Afnemer_Reviews::STATE]['jobs'][$jobId]['state'] = 'sending';
$store[BZV_Afnemer_Reviews::STATE]['jobs'][$jobId]['startedAt'] = time() - 600;
BZV_Afnemer_Reviews::sync();
check(count($mails) === 4, 'An uncertain handoff is never automatically repeated.');
check(!is_wp_error(BZV_Afnemer_Reviews::handle('retry-mail', ['id' => $jobId])), 'Administrator may deliberately retry an uncertain handoff.');
BZV_Afnemer_Reviews::sync();
check(count($mails) === 5, 'Manual retry offers only the selected mail again.');

// Recipient settings use current WordPress accounts, never a pasted email list.
$beforeSettings = BZV_Afnemer_Reviews::settings();
$result = BZV_Afnemer_Reviews::update_recipients(['recipientMode' => 'users', 'recipientUserIds' => ['3', '1', '4', '1'], 'emailEnabled' => false, 'serverKey' => 'untrusted-change']);
check($result['recipientUserIds'] === [1, 3, 4], 'Selection saves normalized, unique user IDs, including non-administrators.');
check(BZV_Afnemer_Reviews::recipients() === ['one@example.test', 'editor@example.test'], 'Only selected current users receive mail, deduplicated case-insensitively.');
check(BZV_Afnemer_Reviews::settings()['serverKey'] === $beforeSettings['serverKey'] && BZV_Afnemer_Reviews::settings()['emailEnabled'], 'Recipient API cannot change secrets or enable/disable mail.');
$options = BZV_Afnemer_Reviews::recipient_options();
check(!$options['users'][2]['canReview'] && !str_contains(json_encode($options), 'server-fixture'), 'Recipient list explains existing review access and exposes no Google secrets.');
foreach ([[], ['recipientMode' => 'wrong'], ['recipientMode' => 'users', 'recipientUserIds' => []],
    ['recipientMode' => 'users', 'recipientUserIds' => [999]], ['recipientMode' => 'users', 'recipientUserIds' => ['1foo']],
    ['recipientMode' => 'users', 'recipientUserIds' => [-1]], ['recipientMode' => 'users', 'recipientUserIds' => [true]],
    ['recipientMode' => 'users', 'recipientUserIds' => '1']] as $input) {
    $saved = $store[BZV_Afnemer_Reviews::SETTINGS];
    check(is_wp_error(BZV_Afnemer_Reviews::update_recipients($input)) && $saved === $store[BZV_Afnemer_Reviews::SETTINGS], 'Invalid or empty enabled selection is rejected without altering settings.');
}
$cap = false;
check(is_wp_error(BZV_Afnemer_Reviews::recipient_options()) && is_wp_error(BZV_Afnemer_Reviews::update_recipients(['recipientMode' => 'administrators'])), 'Non-administrators cannot read the recipient list or change recipients.');
$cap = true;
$store[BZV_Afnemer_Reviews::SETTINGS]['emailEnabled'] = false;
check(!is_wp_error(BZV_Afnemer_Reviews::update_recipients(['recipientMode' => 'users', 'recipientUserIds' => []])) && BZV_Afnemer_Reviews::recipients() === [], 'An empty disabled selection never becomes all users via include=[].');
$store[BZV_Afnemer_Reviews::SETTINGS]['emailEnabled'] = true;
BZV_Afnemer_Reviews::update_recipients(['recipientMode' => 'users', 'recipientUserIds' => [1, 2]]);
$store[BZV_Afnemer_Reviews::STATE] = BZV_Afnemer_State::merge(BZV_Afnemer_State::empty(), BZV_Afnemer_State::validate(json_encode($directory), time()), 'wp_generate_uuid4');
$store[BZV_Afnemer_Reviews::STATE] = BZV_Afnemer_State::queue_notices($store[BZV_Afnemer_Reviews::STATE], BZV_Afnemer_Reviews::recipients(), time(), 'wp_generate_uuid4');
$mails = [];
BZV_Afnemer_Reviews::update_recipients(['recipientMode' => 'users', 'recipientUserIds' => [1]]);
BZV_Afnemer_Reviews::sync();
check(array_column($mails, 'to') === ['one@example.test'], 'Removing a recipient cancels their already queued notification before handoff.');
BZV_Afnemer_Reviews::update_recipients(['recipientMode' => 'users', 'recipientUserIds' => [1, 2]]);
BZV_Afnemer_Reviews::sync();
check(array_column($mails, 'to') === ['one@example.test', 'two@example.test'], 'Reselecting a cancelled recipient sends the outstanding review once without repeating accepted mail.');
$wpUsers[1]->user_email = 'changed@example.test'; unset($wpUsers[2]);
check(BZV_Afnemer_Reviews::recipients() === ['changed@example.test'], 'Deleted users are skipped and changed addresses are resolved from current accounts.');
BZV_Afnemer_Reviews::sync();
check(end($mails)['to'] === 'changed@example.test', 'The next notification uses the current account email.');
$wpUsers[1]->user_email = 'invalid';
check(BZV_Afnemer_Reviews::recipients() === [], 'Invalid current addresses are not delivered.');
$store[BZV_Afnemer_Reviews::SETTINGS]['recipientMode'] = 'selected';
$store[BZV_Afnemer_Reviews::SETTINGS]['recipients'] = ['old@example.test'];
check(!BZV_Afnemer_Reviews::settings()['emailEnabled'] && BZV_Afnemer_Reviews::recipients() === [], 'Legacy free-text recipients fail closed pending explicit user selection.');

if (getenv('BZV_PREVIEW_DIR')) {
    $wpUsers[1]->user_email = 'anna@example.test';
    $store[BZV_Afnemer_Reviews::SETTINGS] = [...$beforeSettings, 'recipientMode' => 'users', 'recipientUserIds' => [1, 3], 'emailEnabled' => false];
    ob_start(); BZV_Afnemer_Admin::recipient_fields(BZV_Afnemer_Reviews::settings()); $html = ob_get_clean();
    file_put_contents(getenv('BZV_PREVIEW_DIR') . '/recipients.html', '<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="afnemer-review.css"><body style="margin:24px;background:#f4f5f6;font-family:system-ui"><main class="bzv-review"><h1>Afnemermeldingen</h1><p>Lokaal voorbeeld met fictieve gebruikers</p><div class="bzv-settings">' . $html . '</div></main><script src="afnemer-review.js"></script></body></html>');
}
echo "Afnemer workflow tests passed: admin-only routes, Google/PDOK approval, Pro output, user selection, current emails, queued cancellation and deduplicated mail.\n";
