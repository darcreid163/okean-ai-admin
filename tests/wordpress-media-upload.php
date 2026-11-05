<?php
/** Real image validation and normal approval lifecycle; disposable WordPress only. */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Media test requires disposable loopback WordPress');
}
function mediaAssert($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
}
function mediaOk($value, $message) {
    mediaAssert(!is_wp_error($value), $message.': '.(is_wp_error($value) ? $value->get_error_code() : ''));
    return $value;
}
function mediaState() {
    global $wpdb;
    $files = [];
    $dir = wp_upload_dir()['basedir'];
    if (is_dir($dir)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) if ($file->isFile()) $files[$file->getPathname()] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);
    $temps = glob(get_temp_dir().'oai-media-validate*') ?: [];
    sort($temps);
    return [$files, $temps,
        (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'"),
        (int)$wpdb->get_var('SELECT COUNT(*) FROM '.OAI_DB::table())];
}
function mediaReject($payload, $code, $import = false) {
    $before = mediaState();
    $calls = 0;
    $spy = function($bits) use (&$calls) { ++$calls; return $bits; };
    add_filter('wp_upload_bits', $spy);
    try {
        $result = $import ? OAI_P0::media_import_url($payload) : OAI_P0::media_upload($payload);
        // Keep this regression safe even when run against the vulnerable source.
        if (is_array($result) && !empty($result['target'])) wp_delete_attachment((int)$result['target'], true);
        mediaAssert(is_wp_error($result) && $result->get_error_code() === $code,
            'Expected '.$code.' before uploads; got '.(is_wp_error($result) ? $result->get_error_code() : 'operation'));
        mediaAssert($calls === 0 && mediaState() === $before, 'Rejected input changed files, attachments or operations');
        $encoded = wp_json_encode($result);
        mediaAssert(strpos($encoded, ABSPATH) === false && strpos($encoded, get_temp_dir()) === false,
            'Validation error disclosed a filesystem path');
    } finally {
        remove_filter('wp_upload_bits', $spy);
    }
}
wp_set_current_user(1);
$preferred = getenv('OAI_MEDIA_TEST_EDITOR');
if ($preferred) {
    mediaAssert(in_array($preferred, ['WP_Image_Editor_GD', 'WP_Image_Editor_Imagick'], true), 'Unexpected CI editor');
    add_filter('wp_image_editors', function() use ($preferred) { return [$preferred]; });
}
mediaAssert(function_exists('imagewebp'), 'CI requires GD with JPEG, PNG, GIF and WebP support');
$image = imagecreatetruecolor(4, 4);
imagefill($image, 0, 0, imagecolorallocate($image, 12, 90, 140));
$fixtures = [];
foreach (['png'=>'image/png', 'jpg'=>'image/jpeg', 'gif'=>'image/gif', 'webp'=>'image/webp'] as $ext=>$mime) {
    ob_start();
    $writer = ['png'=>'imagepng', 'jpg'=>'imagejpeg', 'gif'=>'imagegif', 'webp'=>'imagewebp'][$ext];
    $writer($image);
    $fixtures[$ext] = ['filename'=>'disposable-image.'.$ext, 'mime_type'=>$mime, 'content_base64'=>base64_encode(ob_get_clean())];
}
imagedestroy($image);
$png = $fixtures['png'];
mediaReject(null, 'oai_media_payload');
mediaReject([], 'oai_media_payload');
mediaReject(array_replace($png, ['filename'=>'mismatch.jpg']), 'oai_media_mime');
mediaReject(array_replace($png, ['filename'=>'mismatch.jpg', 'mime_type'=>'image/jpeg']), 'oai_media_mime');
mediaReject(array_replace($png, ['mime_type'=>'image/jpeg']), 'oai_media_mime');
mediaReject(array_replace($png, ['filename'=>'payload.php']), 'oai_media_mime');
mediaReject(array_replace($png, ['content_base64'=>base64_encode('<?php echo "synthetic";')]), 'oai_media_content');
mediaReject(array_replace($png, ['content_base64'=>base64_encode(substr(base64_decode($png['content_base64']), 0, 33))]), 'oai_media_decode');
mediaReject(array_replace($png, ['content_base64'=>'***']), 'oai_media_base64');
mediaReject(array_replace($png, ['content_base64'=>str_repeat('A', 4 * (int)ceil((10 * 1024 * 1024) / 3) + 1)]), 'oai_media_size');
mediaReject(array_replace($png, ['content_base64'=>base64_encode(str_repeat('a', 10 * 1024 * 1024 + 1))]), 'oai_media_size');
foreach (['filename','content_base64','mime_type','title','alt'] as $field) {
    mediaReject(array_replace($png, [$field=>['bad']]), 'oai_media_payload');
}
$huge = base64_decode($png['content_base64']);
$huge = substr_replace($huge, pack('NN', 100000, 100000), 16, 8);
$huge = substr_replace($huge, pack('N', crc32(substr($huge, 12, 17))), 29, 4);
mediaReject(array_replace($png, ['content_base64'=>base64_encode($huge)]), 'oai_media_dimensions');
$noEditors = function() { return []; };
add_filter('wp_image_editors', $noEditors);
try { mediaReject($png, 'oai_media_decoder'); } finally { remove_filter('wp_image_editors', $noEditors); }
// Decoder exceptions must not expose paths or leave temporary validation files.
class OAI_Media_Test_Throwing_Editor extends WP_Image_Editor_GD {
    public function load() { throw new RuntimeException('private decoder detail: '.$this->file); }
}
$throwing = function() { return ['OAI_Media_Test_Throwing_Editor']; };
add_filter('wp_image_editors', $throwing);
try { mediaReject($png, 'oai_media_decode'); } finally { remove_filter('wp_image_editors', $throwing); }
$noPng = function($mimes) { unset($mimes['png']); return $mimes; };
add_filter('upload_mimes', $noPng);
try { mediaReject($png, 'oai_media_mime'); } finally { remove_filter('upload_mimes', $noPng); }
$typePolicy = function($type) { $type['type'] = false; $type['ext'] = false; return $type; };
add_filter('wp_check_filetype_and_ext', $typePolicy);
try { mediaReject($png, 'oai_media_mime'); } finally { remove_filter('wp_check_filetype_and_ext', $typePolicy); }
wp_set_current_user(0);
mediaReject($png, 'oai_media_permission');
wp_set_current_user(1);
echo "PASS: invalid bytes, MIME/extension mismatch, corrupt image, size/pixel limits, field types, site policy, missing decoder and capability denied before persistent writes\n";

$artifacts = [];
$remoteUrl = 'https://8.8.8.8/disposable-image.jpg'; // Public-address fixture; always intercepted below, no network request.
$remoteBody = substr(base64_decode($png['content_base64']), 0, 33);
$remoteRequests = 0;
$remote = function($pre, $args, $url) use ($remoteUrl, &$remoteBody, &$remoteRequests) {
    if ($url !== $remoteUrl) return $pre;
    ++$remoteRequests;
    return ['headers'=>[], 'body'=>$remoteBody, 'response'=>['code'=>200, 'message'=>'OK'], 'cookies'=>[]];
};
add_filter('pre_http_request', $remote, 10, 3);
try {
    mediaReject(['url'=>$remoteUrl], 'oai_media_decode', true);
    $remoteBody = base64_decode($png['content_base64']);
    mediaOk(OAI_License::activate_local_owner(1), 'Activate disposable owner license');
    mediaOk(OAI_AI_Lifecycle_Control::enable(1), 'Enable scoped disposable lifecycle');
    $uploads = $fixtures;
    $uploads['import'] = ['url'=>$remoteUrl, 'filename'=>'remote-wrong.jpg'];
    foreach ($uploads as $ext=>$payload) {
        // Omitting a redundant declaration is supported; JPEG aliases remain valid.
        if ($ext === 'jpg') { unset($payload['mime_type']); $payload['filename'] = 'disposable-image.jpeg'; }
        $before = mediaState();
        $op = mediaOk($ext === 'import' ? OAI_P0::media_import_url($payload) : OAI_P0::media_upload($payload), 'Plan '.$ext.' upload');
        $id = (int)$op['target'];
        $artifacts[] = $id;
        $file = get_attached_file($id);
        mediaAssert($op['status'] === 'pending' && get_post_status($id) === 'private' && is_file($file), 'Image must remain a private pending artifact');
        mediaAssert(get_post_mime_type($id) === $fixtures[$ext === 'import' ? 'png' : $ext]['mime_type'], 'Attachment MIME must match real bytes');
        if ($ext === 'import') mediaAssert(basename($file) === 'remote-wrong.png' && $op['imported_via'] === 'media.import_url_sandbox', 'Remote filename normalization regressed');
        mediaAssert(!empty(wp_get_attachment_metadata($id)['width']), 'Valid image metadata was not generated');
        foreach (['approve','apply'] as $action) mediaOk(OAI_AI_Lifecycle_Control::perform((int)$op['id'], $action, 'disposable-media-lifecycle', 1), $action.' '.$ext);
        mediaAssert(!empty(mediaOk(OAI_Verify::operation((int)$op['id']), 'Verify apply')['verified']), 'Applied image failed verification');
        mediaAssert(get_post($id)->post_status === 'inherit', 'Approval/apply did not publish attachment');
        mediaOk(OAI_AI_Lifecycle_Control::perform((int)$op['id'], 'rollback', 'disposable-media-lifecycle', 1), 'Rollback '.$ext);
        mediaAssert(!empty(mediaOk(OAI_Verify::operation((int)$op['id']), 'Verify rollback')['verified']), 'Image rollback failed verification');
        mediaAssert(!get_post($id) && !is_file($file), 'Rollback left attachment or original file');
        mediaAssert(mediaState()[0] === $before[0] && mediaState()[1] === $before[1], 'Upload lifecycle left file artifacts');
    }
    mediaAssert($remoteRequests === 2, 'Both URL imports must use intercepted HTTP responses');
    echo "PASS: JPEG (jpeg alias/no declared MIME), PNG, GIF, WebP and intercepted URL import: private pending → approve → apply → verify → rollback → verify; files cleaned\n";
    echo 'PASS: image editor '.($preferred ?: 'WordPress default')."\n";
} finally {
    remove_filter('pre_http_request', $remote, 10);
    foreach ($artifacts as $id) if (get_post($id)) wp_delete_attachment($id, true);
    OAI_AI_Lifecycle_Control::disable(1, 'disposable_ci_teardown');
    OAI_License::deactivate_local_owner();
}
