<?php
/** Rich text is deliberately restricted to formatting, never links or attributes. */
function parent_letter_message_text($message): string {
    if (is_string($message)) return $message;
    if (!is_array($message)) return '';
    $html=(string)($message['html']??'');
    $html=preg_replace('/<br\s*\/?\s*>/i',"\n",$html);
    $html=preg_replace('/<\/(p|li)>/i',"\n",$html);
    return preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u','',html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'));
}

function parent_letter_message_normalize($message) {
    if (is_string($message)) {
        if (!mb_check_encoding($message,'UTF-8') || mb_strlen($message,'UTF-8')>2000) throw new InvalidArgumentException('Pesan setiap siswa maksimal 2.000 karakter.');
        return trim(str_replace(["\r\n","\r"],"\n",$message));
    }
    if (!is_array($message) || ($message['format']??'')!=='rich_text' || !is_string($message['html']??null)) throw new InvalidArgumentException('Format pesan surat tidak valid.');
    $source=$message['html'];
    if (strlen($source)>40000 || !mb_check_encoding($source,'UTF-8')) throw new InvalidArgumentException('Format pesan terlalu besar atau tidak valid.');
    $doc=new DOMDocument('1.0','UTF-8');
    $previous=libxml_use_internal_errors(true);
    try {$doc->loadHTML('<?xml encoding="UTF-8"><!doctype html><html><body>'.$source.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
    finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
    $allowed=['p','br','strong','em','u','ul','ol','li'];
    $blocked=['script','style','iframe','object','embed','svg','math','form','input','button','textarea','select','template','head'];
    $clean=function(DOMNode $node,int $depth=0)use(&$clean,$allowed,$blocked):string{
        if($depth>100)throw new InvalidArgumentException('Format pesan terlalu rumit.');
        if($node instanceof DOMText)return htmlspecialchars($node->nodeValue,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        if(!($node instanceof DOMElement))return '';
        $tag=strtolower($node->tagName);
        if(in_array($tag,$blocked,true))return '';
        $tag=['b'=>'strong','i'=>'em','div'=>'p'][$tag]??$tag;
        $inside='';foreach($node->childNodes as $child)$inside.=$clean($child,$depth+1);
        if(!in_array($tag,$allowed,true))return $inside;
        return $tag==='br'?'<br>':'<'.$tag.'>'.$inside.'</'.$tag.'>';
    };
    $html='';foreach($doc->getElementsByTagName('body')->item(0)->childNodes as $node)$html.=$clean($node);
    $result=['format'=>'rich_text','html'=>$html];
    $text=parent_letter_message_text($result);
    if(mb_strlen($text,'UTF-8')>2000)throw new InvalidArgumentException('Pesan setiap siswa maksimal 2.000 karakter.');
    return $text===''?'':$result;
}

function parent_letter_message_html($message): string {
    $message=parent_letter_message_normalize($message);
    if(is_array($message))return '<div class="parent-custom-message">'.$message['html'].'</div>';
    if($message==='')return '';
    $html='';foreach(preg_split('/\n\s*\n/u',$message) as $paragraph)$html.='<p>'.nl2br(htmlspecialchars($paragraph,ENT_QUOTES,'UTF-8')).'</p>';
    return '<div class="parent-custom-message">'.$html.'</div>';
}
