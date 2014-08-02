<?php
/**
 * 
 * WeChat Interface for WeChat Subscribers Lite
 * 
 * 
 *   
 */

global $token;

define('IS_DEBUG', false);

$wechatObj = new wechatCallbackapi($token);

$valid=$wechatObj->valid();

if($valid){
	$wechatObj->responseMsg(get_data());
}else{
	header('Location: '.home_url());
}
exit;

class wechatCallbackapi{

	private $token;
	private $data;
	
	public function __construct($_token, $_data=null){
		$this->token=$_token;
		if($_data!=null){
			$this->load($_data);
		}
	}
	
	public function load($_data){
		$this->data=$_data;
	}
	
	public function valid(){
		if(isset($_GET["echostr"])){
	    	$echoStr = $_GET["echostr"];
	    }
	    //valid signature , option
	    if($this->checkSignature()){
	    	if(isset($echoStr) && $echoStr!=''){
	    		echo $echoStr;
	    		exit;
	    	}
	    	return true;
	    }else{
	    	return false;
	    }
	}

    public function responseMsg($_data=null){
    	if($_data!=null){
    		$this->load($_data);
    	}
    	
		//get post data, May be due to the different environments
		if(IS_DEBUG){
			$postStr="<xml>
						<ToUserName><![CDATA[toUser]]></ToUserName>
						<FromUserName><![CDATA[fromUser]]></FromUserName> 
						<CreateTime>1348831860</CreateTime>
						<MsgType><![CDATA[text]]></MsgType>
						<Content><![CDATA[1]]></Content>
						<MsgId>1234567890123456</MsgId>
						</xml>";
		}else{
			$postStr = $GLOBALS["HTTP_RAW_POST_DATA"];
		}
		
      	//extract post data
		if (!empty($postStr) && $this->checkSignature() && isset($this->data)){
                
              	$postObj = simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);
				$msgType=$postObj->MsgType;

				if($msgType=='event'){
					$msg=$this->eventRespon($postObj);
				}else{
					$msg=$this->sendAutoReply($postObj);
				}
				echo $msg;
        }else {
        	echo "";
        	exit;
        }
    }
	private function saveKeyWord($fromUsername,$keyword,$match){
        $messageRow = array("openid"=>$fromUsername,"keyword"=>$keyword,"is_match"=>$match,"time"=>current_time("Y-m-d H:i:s",0));
        global $wpdb;
		$rows_affected = $wpdb->insert("wechat_subscribers_lite_history",$messageRow);
	}


	private function sendAutoReply($postObj){

        $fromUsername = $postObj->FromUserName;
        $toUsername = $postObj->ToUserName;
        $keyword = trim($postObj->Content);
		$resultStr='';
		
		$is_match=false;
		if($keyword!=''){
			foreach($this->data as $d){
				if($d->trigger=='default' || $d->trigger=='subscribe'){
					continue;
				}
				$curr_key=$d->key;
				foreach($curr_key as $k){
					if(strtolower($keyword) == strtolower(trim($k))){
							$is_match=true;
					}
				}
				
				if($is_match){
					$resultStr =$this->get_msg_by_type($d, $fromUsername, $toUsername);
					break;
				}
				
			}
		}
		$match = $is_match ? "y" : "n";
		if(!$is_match){
			foreach($this->data as $d){
				if($d->trigger=='default' && !$is_match){
					$is_match=true;
					if($is_match){
						$resultStr =$this->get_msg_by_type($d, $fromUsername, $toUsername); 
						break;
					}
				}
			}
		}
		$this->saveKeyWord($fromUsername,$keyword,$match);
		return $resultStr;
	}
	

	private function eventRespon($postObj){
		
        $fromUsername = $postObj->FromUserName;
        $toUsername = $postObj->ToUserName;
		$eventType=$postObj->Event;
		$resultStr='';
		
		foreach($this->data as $d){
			if($d->trigger == $eventType){
				$resultStr =$this->get_msg_by_type($d, $fromUsername, $toUsername); 
				break;
			}
		}
		
		return $resultStr;
	}
	private function parseurl($url=""){
	    $url = rawurlencode($url);
	    $a = array("%3A", "%2F", "%40");
	    $b = array(":", "/", "@");
	    $url = str_replace($a, $b, $url);
	    return $url;
    }

	private function get_msg_by_type($d, $fromUsername, $toUsername){
		switch($d->type){
			case "news":
				$resultStr = $this->sendPhMsg($fromUsername, $toUsername, $d->phmsg);
			break;
			case "recently": 
			    $resultStr = $this->sendReMsg($fromUsername, $toUsername, $d->remsg); 
			break;
			default: //text
				$resultStr = $this->sendMsg($fromUsername, $toUsername, $d->msg);
		}
		
		return $resultStr;
	}
	
	private function sendMsg($fromUsername, $toUsername, $contentData){

		if($contentData==''){
			return '';
		}
	
        $textTpl = "<xml>
					<ToUserName><![CDATA[%s]]></ToUserName>
					<FromUserName><![CDATA[%s]]></FromUserName>
					<CreateTime>%s</CreateTime>
					<MsgType><![CDATA[%s]]></MsgType>
					<Content><![CDATA[%s]]></Content>
					<FuncFlag>0</FuncFlag>
					</xml>";

		$msgType = "text";
		$time = time();
		$resultStr = sprintf($textTpl, $fromUsername, $toUsername, $time, $msgType, $contentData);
		return $resultStr;
	}	

	private function sendPhMsg($fromUsername, $toUsername, $contentData){
		if($contentData==''){
			return '';
		}
		
        $headerTpl = "<ToUserName><![CDATA[%s]]></ToUserName>
			        <FromUserName><![CDATA[%s]]></FromUserName>
			        <CreateTime>%s</CreateTime>
			        <MsgType><![CDATA[%s]]></MsgType>
			        <ArticleCount>%s</ArticleCount>";
			        
		$itemTpl=  "<item>
					<Title><![CDATA[%s]]></Title> 
					<Description><![CDATA[%s]]></Description>
					<PicUrl><![CDATA[%s]]></PicUrl>
					<Url><![CDATA[%s]]></Url>
					</item>";
		$itemStr="";
		$mediaCount=0;
		foreach ($contentData as $mediaObject){
			$title=$mediaObject->title;
			$des= $mediaObject->des;
			$media=$this->parseurl($mediaObject->pic);
			$url=$mediaObject->url;
			$itemStr .= sprintf($itemTpl, $title, $des, $media, $url);
			$mediaCount++;
		}
		
		$msgType = "news";
		$time = time();
		$headerStr = sprintf($headerTpl, $fromUsername, $toUsername, $time, $msgType, $mediaCount);
		$resultStr ="<xml>".$headerStr."<Articles>".$itemStr."</Articles></xml>";

		return $resultStr;
	}
    private function getRecentlyPosts($contentData = null){
    	if(!$contentData) return null;
    	$re_type  = isset($contentData['type']) ?$contentData['type'] :"";
		$re_cate  = isset($contentData['cate']) ?$contentData['cate'] :"";
		$re_count = isset($contentData['count'])?$contentData['count']:"";
        $args = array(
		'posts_per_page'   => $re_count,
		'orderby'          => 'post_date',
		'order'            => 'desc',
		'post_type'        => $re_type,
		);
		if($re_type=="post"){
			if($re_cate!="all"){
               $args['category'] = $re_cate;
			}
			$args['post_status'] = "publish";
		}else if($re_type=="page"){
            $args['post_status'] = "publish";
        }
        
		$posts = get_posts($args);
		return $posts;
    }
    private function getImgsSrcInPost($post_id=null,$post_content=null,$i,$type,$post_excerpt){

	    	$imageSize = $i == 1 ? "sup_wechat_big":"sup_wechat_small";
	    	$text = "";
	    	$rimg = WPWSL_PLUGIN_URL."/img/".$imageSize.".png";;
	    	if($type=="attachment"){
	           $rimg = wp_get_attachment_image_src($post_id,$imageSize)[0];
	    	}else{
		    	if(get_the_post_thumbnail($post_id)!=''){
				   $rimg = wp_get_attachment_image_src(get_post_thumbnail_id($post_id),$imageSize)[0];
				}else{
					$attachments = get_posts( array(
						'post_type' => 'attachment',
						'posts_per_page' => -1,
						'post_parent' => $post_id,
						'exclude'     => get_post_thumbnail_id($post_id)
					));
					if(count($attachments)>0){
						$rimg=wp_get_attachment_image_src($attachments[0]->ID,$imageSize)[0];
					}
				}	
	    	}
	    	if(trim($post_excerpt)!=""){
                    $text = $post_excerpt;
                }else if(trim($post_content!="")){
					$text = mb_substr(strip_tags($post_content),0,SYNC_EXCERPT_LIMIT,DB_CHARSET);
					$text = mb_strlen(strip_tags($post_content),DB_CHARSET)>SYNC_EXCERPT_LIMIT ? $text."..." : $text;
				}
	    	// if($rimg) 
	    	$result = array("src"=>$rimg,"text"=>$text);
	    	// else $result = array("src"=>WPWSL_PLUGIN_URL."/img/trans.png","text"=>$text);

    	
        return $result;
    }
	private function sendReMsg($fromUsername, $toUsername, $contentData){
		if($contentData==''){
			return '';
		}
		$posts = $this->getRecentlyPosts($contentData);
        $headerTpl = "<ToUserName><![CDATA[%s]]></ToUserName>
			        <FromUserName><![CDATA[%s]]></FromUserName>
			        <CreateTime>%s</CreateTime>
			        <MsgType><![CDATA[%s]]></MsgType>
			        <ArticleCount>%s</ArticleCount>";
			        
		$itemTpl=  "<item>
					<Title><![CDATA[%s]]></Title> 
					<Description><![CDATA[%s]]></Description>
					<PicUrl><![CDATA[%s]]></PicUrl>
					<Url><![CDATA[%s]]></Url>
					</item>";
		$itemStr="";
		$mediaCount=0;
		$i=1;
		foreach ($posts as $mediaObject){
		    $src_and_text = $this->getImgsSrcInPost($mediaObject->ID,$mediaObject->post_content,$i,$contentData['type'],$mediaObject->post_excerpt);			
			$title= $mediaObject->post_title;
			$des  = $src_and_text['text'];  // strip_tags or not
			$media= $this->parseurl($src_and_text['src']);;
			$url  = $contentData['type']=="attachment"?home_url('/?attachment_id='.$mediaObject->ID):$mediaObject->guid;
			$itemStr .= sprintf($itemTpl, $title, $des, $media, $url);
			$mediaCount++;
			$i++;
		}
		
		$msgType = "news";
		$time = time();
		$headerStr = sprintf($headerTpl, $fromUsername, $toUsername, $time, $msgType, $mediaCount);
		$resultStr ="<xml>".$headerStr."<Articles>".$itemStr."</Articles></xml>";

		return $resultStr;
	}
	
	private function checkSignature(){
		if(IS_DEBUG){
			return true;
		}
		$signature =isset($_GET["signature"])?$_GET["signature"]:'';
		$timestamp =isset($_GET["timestamp"])?$_GET["timestamp"]:'';
        $nonce = isset($_GET["nonce"])?$_GET["nonce"]:'';	
        		
		$token = $this->token;
		$tmpArr = array($token, $timestamp, $nonce);
		sort($tmpArr,SORT_STRING);
		$tmpStr = implode( $tmpArr );
		$tmpStr = sha1( $tmpStr );
		
		if( $tmpStr == $signature ){
			return true;
		}else{
			return false;
		}
	}
}

function get_data(){
	$args = array(
			'post_type' => 'wpwsl_template',
			'posts_per_page' => -1,
			'orderby' => 'date',
			'post_status' => 'publish',
			'order'=> 'DESC'
	);
	
	$raw=get_posts($args);
	
	$data = array();
	
	foreach($raw as $p){
		$_gp=get_post_meta($p->ID,'_phmsg_item');
		$phmsg_group=array();
		
		foreach($_gp as $_item){
			$_tmp_item=json_decode($_item);
			
			$_tmp_item->title=urldecode($_tmp_item->title);
			$_tmp_item->pic=urldecode($_tmp_item->pic);
			$_tmp_item->des=urldecode($_tmp_item->des);
			$_tmp_item->url=urldecode($_tmp_item->url);
		
			$phmsg_group[]=$_tmp_item;
		}
		$tmp_key=trim(get_post_meta($p->ID,'_keyword',TRUE));
		$array_key=explode(',', $tmp_key);
		
		
		$tmp_msg=new stdClass();
		
		$tmp_msg->title=$p->post_title;
		$tmp_msg->type=get_post_meta($p->ID,'_type',TRUE);
		$tmp_msg->key=$array_key;
		$tmp_msg->trigger=get_post_meta($p->ID,'_trigger',TRUE);
		$tmp_msg->msg=get_post_meta($p->ID,'_content',TRUE);
		$tmp_msg->phmsg=$phmsg_group;
		//recently
		$tmp_msg->remsg=array(
			                  "type"=>get_post_meta($p->ID,'_re_type',TRUE),
			                  "cate"=>get_post_meta($p->ID,'_re_cate',TRUE),
			                  "count"=>get_post_meta($p->ID,'_re_count',TRUE)
			                  );
	
		$data[]=$tmp_msg;
	}
	
	return $data;
}
?>