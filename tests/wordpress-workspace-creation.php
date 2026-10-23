<?php
/** Disposable WordPress regression: creation must reserve before filesystem side effects. */
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1')throw new RuntimeException('Disposable guard required');
$actor=wp_create_user('workspace-admission-ci','ci-local-only-password','workspace-ci@example.invalid');
if(is_wp_error($actor))throw new RuntimeException('Could not create disposable workspace fixture user');
(new WP_User((int)$actor))->set_role('administrator');
wp_set_current_user((int)$actor);
$before=OAI_Workspace::list_workspaces();
$first=OAI_Workspace::create(false);
if(is_wp_error($first)||empty($first['id']))throw new RuntimeException('First workspace creation failed: '.(is_wp_error($first)?$first->get_error_code().' '.$first->get_error_message():'missing ID'));
$second=OAI_Workspace::create(false);
if(!is_wp_error($second)||$second->get_error_code()!=='oai_workspace_creation_pending')throw new RuntimeException('Retry incorrectly created second workspace');
$after=OAI_Workspace::list_workspaces();
if(count($after['items'])!==count($before['items'])+1)throw new RuntimeException('Duplicate workspace artifact created');
$id=$first['id'];if(!is_file(OAI_Workspace::root().'/'.$id.'/workspace.json'))throw new RuntimeException('First workspace missing');
wp_set_current_user(0);
$unauthorized=OAI_Workspace::create(false);
if(!is_wp_error($unauthorized)||$unauthorized->get_error_code()!=='oai_workspace_auth')throw new RuntimeException('Anonymous workspace accepted');
echo "PASS workspace creation atomic admission, duplicate rejection, artifact count, anonymous denial\n";
