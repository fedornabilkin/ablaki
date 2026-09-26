<?php
$privacyTx=$db->beginTransaction();
try {
    list($status,$private)=dispatch('POST','v1/forum-theme',true,[],['title'=>'Private topic 72','is_private'=>1]);
    routeCheck($status===201 && $private['is_private']===true,'author creates a private topic');
    $id=(int)$private['id'];
    $created=(int)$private['created_at'];
    list($status,$comment)=dispatch('POST','v1/forum-comment',true,[],['theme_id'=>$id,'comment'=>'Secret message 72']);
    routeCheck($status===201,'member posts in private topic');
    $commentId=(int)$comment['id'];
    foreach (['v1/forum-theme/'.$id,'v1/forum-comment/'.$commentId,'v1/forum-comment/'.$commentId.'/gifts'] as $path) routeCheck(dispatch('GET',$path,false,['expand'=>'user,theme,first_comment'])[0]===404,'guest direct read is hidden: '.$path);
    routeCheck(dispatch('POST','v1/forum-theme/'.$id.'/visit')[0]===404,'guest cannot increment private topic views');
    foreach (['forum-theme'=>'Private topic 72','forum-comment'=>'Secret message 72'] as $resource=>$search) {
        $page=dispatch('GET','v1/'.$resource,false,['envelope'=>'1','q'=>$search,'expand'=>'user,theme,first_comment'])[1];
        routeCheck($page['_meta']['totalCount']===0 && !$page['items'],'guest search and count hide private '.$resource);
    }
    $page=dispatch('GET','v1/forum-comment',false,['envelope'=>'1','filter'=>['theme_id'=>$id]])[1];
    routeCheck(!$page['items'] && $page['_meta']['totalCount']===0,'guest theme filter cannot reveal comments');
    $member=dispatch('GET','v1/forum-theme/'.$id,true,['expand'=>'first_comment']);
    routeCheck($member[0]===200 && $member[1]['first_comment']['comment']==='Secret message 72','authenticated member can read private topic and starter');
    $app->user->setIdentity(null);
    routeCheck(\common\modules\forum\models\ForumTheme::find()->alias('t')->where(['t.id'=>$id])->one()===null && \common\modules\forum\models\ForumComment::find()->alias('c')->where(['c.id'=>$commentId])->one()===null,'server-rendered and aliased queries enforce guest privacy');
    $db->createCommand()->update('forum_theme',['user_id'=>2],['id'=>$id])->execute();
    routeCheck(dispatch('PATCH','v1/forum-theme/'.$id,true,[],['is_private'=>0])[0]===403,'another member cannot publish private topic');
    $db->createCommand()->update('forum_theme',['user_id'=>1],['id'=>$id])->execute();
    foreach ([2,-1,'yes',[]] as $invalid) routeCheck(dispatch('PATCH','v1/forum-theme/'.$id,true,[],['is_private'=>$invalid])[0]===422,'invalid privacy flag rejected');
    list($status,$public)=dispatch('PATCH','v1/forum-theme/'.$id,true,[],['is_private'=>0,'user_id'=>2,'created_at'=>1]);
    routeCheck($status===200 && !$public['is_private'] && (int)$public['user_id']===1 && (int)$public['created_at']===$created,'author toggles privacy without changing original authorship or date');
    routeCheck(dispatch('GET','v1/forum-theme/'.$id)[0]===200 && dispatch('GET','v1/forum-comment/'.$commentId)[0]===200,'published topic and messages become visible');
    foreach ([0,-1,1.5,101,null] as $quantity) {
        routeCheck(dispatch('POST','v1/exchange',true,[],['type'=>'buy','credit'=>1,'amount'=>.01,'count'=>$quantity])[0]===422,'invalid exchange quantity rejected before any debit');
        foreach (['orel','saper'] as $game) routeCheck(dispatch('POST','v1/'.$game,true,[],['kon'=>1,'count'=>$quantity])[0]===400,'invalid '.$game.' quantity rejected before any debit');
    }
} finally { $privacyTx->rollBack(); }
