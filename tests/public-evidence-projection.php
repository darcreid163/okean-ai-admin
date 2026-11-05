<?php
/** Synthetic serialization/path adversarial cases; no WordPress or network. */
define('ABSPATH','/srv/current-wordpress/');
define('OAI_DIR',ABSPATH.'wp-content/plugins/okean-ai-admin/');
define('WP_CONTENT_DIR',ABSPATH.'wp-content');
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
require dirname(__DIR__).'/includes/class-oai-security.php';
function projectionAssert($ok,$message){if(!$ok)throw new RuntimeException($message);}
$fixture=[
 'hash'=>str_repeat('a',64),'verified'=>true,'phase'=>'after',
 'verification'=>['file'=>ABSPATH.'wp-content/uploads/image.png','old_file'=>'/legacy-mount/tenant/image.png',
  'windows'=>'C:\\PrivateTenant\\image.png','unc'=>'\\\\private-server\\share\\image.png',
  'file_uri'=>'file:///legacy-mount/private-image.png',
  'message'=>'Decode failed at /unusual-mount/private/image.png',
  'root_error'=>'Failed in /var/old-tenant/image.png'],
 'receipt'=>'{"verification":{"file":"/srv/old-site/image.png","empty":{},"list":[],"float":1.0}}',
 'malformed'=>['proof'=>'{"file":"\\/legacy-mount\\/private.png"'],
 'url'=>'https://example.test/srv/current-wordpress/image.png',
 'url2'=>'https://example.test/var/image.png','relative'=>'includes/class-oai-rest.php',
 'route'=>'/wp-json/okean-ai/v1/operations/1','text'=>'/various-choices',
 'keyed'=>['/srv/a/image.png'=>1,'/srv/b/image.png'=>2],
];
$original=serialize($fixture);
$public=OAI_Security::public_evidence($fixture);
$json=json_encode($public,JSON_UNESCAPED_SLASHES);
foreach(['current-wordpress/wp-content','old-site','PrivateTenant','private-server','unusual-mount','old-tenant','legacy-mount'] as $private)projectionAssert(strpos($json,$private)===false,'Path disclosed: '.$private);
projectionAssert($public['url']===$fixture['url']&&$public['url2']===$fixture['url2'],'Public URLs changed');
projectionAssert($public['relative']===$fixture['relative']&&$public['route']===$fixture['route']&&$public['text']===$fixture['text'],'Logical relative/route identifiers changed');
projectionAssert($public['hash']===$fixture['hash']&&$public['verified']===true&&$public['phase']==='after','Proof claims changed');
projectionAssert($public['malformed']['proof']==='[invalid serialized evidence]','Malformed serialized proof disclosed its raw body');
projectionAssert(serialize($fixture)===$original,'Original evidence modified');
projectionAssert(count($public['keyed'])===2&&array_values($public['keyed'])===[1,2],'Path-keyed records collapsed');
$collision='[server-path]:'.substr(hash('sha256','/srv/a/image.png'),0,12);
$collisionView=OAI_Security::public_evidence(['items'=>['/srv/a/image.png'=>1,$collision=>2]]);
projectionAssert(count($collisionView['items'])===2&&array_values($collisionView['items'])===[1,2],'User-supplied display-key collision hid evidence');
$decoded=json_decode($public['receipt']);
projectionAssert($decoded->verification->empty instanceof stdClass&&$decoded->verification->list===[]&&is_float($decoded->verification->float),'Serialized JSON object/list/float contract changed');
projectionAssert($public['_wpai_projection']['hashes_attest']==='original_evidence'&&$public['_wpai_projection']['canonical_payload_available']===false,'Missing explicit hash/projection contract');
projectionAssert(OAI_Security::public_evidence($public)===$public,'Projection is not idempotent');
projectionAssert(OAI_Security::public_evidence(['hash'=>'clean','verified'=>false])===['hash'=>'clean','verified'=>false],'Clean evidence shape changed');
$object=(object)['file'=>'/legacy-mount/private.png','verified'=>false];
$objectView=OAI_Security::public_evidence($object);
projectionAssert($object->file==='/legacy-mount/private.png'&&$objectView->file==='[server-path]'&&isset($objectView->_wpai_projection),'Object projection mutated original or omitted contract');
echo "PASS: path roots, legacy/custom/Windows/UNC paths, inline errors, serialized JSON, path-key collisions, proof claims, original immutability, URLs and idempotence\n";
