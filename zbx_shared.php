<?php

function exitWithError($msg, $code = 1)
{
    fwrite(STDERR, "FAILED: {$msg}\n");
    exit($code);
}

?>