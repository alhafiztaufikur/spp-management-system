<?php
/** Split oversized cells into bounded continuation rows without losing characters. */
function report_pdf_text_chunks(string $value):array {
    if($value==='')return [''];$chunks=[];$chunk='';$lines=0;
    foreach(mb_str_split($value) as $char){$chunk.=$char;if($char==="\n")$lines++;
        if(mb_strlen($chunk)>=480||$lines>=8){$chunks[]=$chunk;$chunk='';$lines=0;}}
    if($chunk!=='')$chunks[]=$chunk;return $chunks;
}
