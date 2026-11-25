<?php
/** Synthetic serialization/path adversarial cases; no WordPress or network. */
define('ABSPATH','/srv/current-wordpress/');
define('OAI_DIR',ABSPATH.'wp-content/plugins/okean-ai-admin/');
define('WP_CONTENT_DIR',ABSPATH.'wp-content');
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
require dirname(__DIR__).'/includes/class-oai-security.php';
function projectionAssert($ok,$message){if(!$ok)throw new RuntimeException($message);}
$fixture=[
 'hash'=>str_repeat('a',64),'proof_hash'=>str_repeat('b',64),'sha256'=>str_repeat('c',64),'verified'=>true,'phase'=>'after',
 'credentials'=>['api_key'=>'public-evidence-api-key-should-not-leak','accessToken'=>'public-evidence-token-should-not-leak','label'=>'Safe sibling'],
 'password_hash'=>'public-evidence-password-hash-should-not-leak',
 'security_status'=>['refresh_token_supported'=>false,'api_key_required'=>true,'access_token_present'=>false,'signature_valid'=>true],
 'verification'=>['file'=>ABSPATH.'wp-content/uploads/image.png','old_file'=>'/legacy-mount/tenant/image.png',
  'windows'=>'C:\\PrivateTenant\\image.png','unc'=>'\\\\private-server\\share\\image.png',
  'file_uri'=>'file:///legacy-mount/private-image.png',
  'message'=>'Decode failed at /unusual-mount/private/image.png',
  'root_error'=>'Failed in /var/old-tenant/image.png'],
 'receipt'=>'{"verification":{"file":"/srv/old-site/image.png","empty":{},"list":[],"float":1.0},"integration":{"client_secret":"serialized-secret-should-not-leak","label":"Safe serialized sibling","proof_hash":"'.str_repeat('d',64).'"}}',
 'malformed'=>['proof'=>'{"file":"\\/legacy-mount\\/private.png"'],
 'url'=>'https://example.test/srv/current-wordpress/image.png',
 'url2'=>'https://example.test/var/image.png','relative'=>'includes/class-oai-rest.php',
 'route'=>'/wp-json/okean-ai/v1/operations/1','text'=>'/various-choices',
 'keyed'=>['/srv/a/image.png'=>1,'/srv/b/image.png'=>2],
];
$operationFixture=[
 'operation_id'=>'op_fixture','type'=>'elementor_widget_settings',
 'before_data'=>['integration'=>['api_key'=>'operation-before-key-should-not-leak','label'=>'Safe before']],
 'after_data'=>'{"integration":{"access_token":"operation-after-token-should-not-leak","label":"Safe after"}}',
 'diff_data'=>['changed'=>true,'before'=>['client_secret'=>'operation-diff-secret-should-not-leak'],'after'=>['label'=>'Safe diff']],
 'proof_hash'=>str_repeat('e',64),
];
$publicOperation=OAI_Security::public_evidence($operationFixture);
$operationJson=json_encode($publicOperation,JSON_UNESCAPED_SLASHES);
projectionAssert(strpos($operationJson,'operation-before-key-should-not-leak')===false&&strpos($operationJson,'operation-after-token-should-not-leak')===false&&strpos($operationJson,'operation-diff-secret-should-not-leak')===false,'Operation ledger projection leaked secret values');
projectionAssert($publicOperation['before_data']['integration']['api_key']==='[REDACTED]'&&$publicOperation['before_data']['integration']['label']==='Safe before','Operation before snapshot redaction failed');
$publicAfter=json_decode($publicOperation['after_data'],true);
projectionAssert($publicAfter['integration']['access_token']==='[REDACTED]'&&$publicAfter['integration']['label']==='Safe after','Serialized operation after snapshot redaction failed');
projectionAssert($publicOperation['proof_hash']===$operationFixture['proof_hash'],'Operation proof hash changed during public projection');

$original=serialize($fixture);
$public=OAI_Security::public_evidence($fixture);
$json=json_encode($public,JSON_UNESCAPED_SLASHES);
foreach(['current-wordpress/wp-content','old-site','PrivateTenant','private-server','unusual-mount','old-tenant','legacy-mount'] as $private)projectionAssert(strpos($json,$private)===false,'Path disclosed: '.$private);
projectionAssert($public['url']===$fixture['url']&&$public['url2']===$fixture['url2'],'Public URLs changed');
projectionAssert($public['relative']===$fixture['relative']&&$public['route']===$fixture['route']&&$public['text']===$fixture['text'],'Logical relative/route identifiers changed');
projectionAssert($public['hash']===$fixture['hash']&&$public['verified']===true&&$public['phase']==='after','Proof claims changed');
projectionAssert($public['proof_hash']===$fixture['proof_hash']&&$public['sha256']===$fixture['sha256'],'Proof and SHA-256 integrity fields changed');
projectionAssert($public['credentials']['api_key']==='[REDACTED]'&&$public['credentials']['accessToken']==='[REDACTED]'&&$public['credentials']['label']==='Safe sibling','Nested credentials were not redacted or safe siblings were lost');
projectionAssert($public['password_hash']==='[REDACTED]','Password hash value was not redacted');
projectionAssert($public['security_status']===['refresh_token_supported'=>false,'api_key_required'=>true,'access_token_present'=>false,'signature_valid'=>true],'OAuth/security capability status flags were incorrectly redacted');
projectionAssert(strpos($json,'public-evidence-api-key-should-not-leak')===false&&strpos($json,'serialized-secret-should-not-leak')===false,'Secret value remained in public evidence JSON');
projectionAssert($public['malformed']['proof']==='[invalid serialized evidence]','Malformed serialized proof disclosed its raw body');
projectionAssert(serialize($fixture)===$original,'Original evidence modified');
projectionAssert(count($public['keyed'])===2&&array_values($public['keyed'])===[1,2],'Path-keyed records collapsed');
$collision='[server-path]:'.substr(hash('sha256','/srv/a/image.png'),0,12);
$collisionView=OAI_Security::public_evidence(['items'=>['/srv/a/image.png'=>1,$collision=>2]]);
projectionAssert(count($collisionView['items'])===2&&array_values($collisionView['items'])===[1,2],'User-supplied display-key collision hid evidence');
$decoded=json_decode($public['receipt']);
projectionAssert($decoded->verification->empty instanceof stdClass&&$decoded->verification->list===[]&&is_float($decoded->verification->float),'Serialized JSON object/list/float contract changed');
projectionAssert($decoded->integration->client_secret==='[REDACTED]'&&$decoded->integration->label==='Safe serialized sibling'&&$decoded->integration->proof_hash===str_repeat('d',64),'Serialized JSON secret redaction or proof hash preservation failed');
projectionAssert($public['_wpai_projection']['hashes_attest']==='original_evidence'&&$public['_wpai_projection']['canonical_payload_available']===false,'Missing explicit hash/projection contract');
projectionAssert(OAI_Security::public_evidence($public)===$public,'Projection is not idempotent');
projectionAssert(OAI_Security::public_evidence(['hash'=>'clean','verified'=>false])===['hash'=>'clean','verified'=>false],'Clean evidence shape changed');
$object=(object)['file'=>'/legacy-mount/private.png','verified'=>false];
$objectView=OAI_Security::public_evidence($object);
projectionAssert($object->file==='/legacy-mount/private.png'&&$objectView->file==='[server-path]'&&isset($objectView->_wpai_projection),'Object projection mutated original or omitted contract');
echo "PASS: path roots, legacy/custom/Windows/UNC paths, inline errors, serialized JSON, path-key collisions, proof claims, original immutability, URLs and idempotence\n";
