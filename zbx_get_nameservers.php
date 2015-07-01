#!/usr/bin/php
<?php
if($options = getopt('h:'));

if(!$host = @$options['h'])
{
    fwrite(STDERR, "Supply a hostname with -h\n");
    exit(2);
}


function removeDot(&$value, $key)
{
    $value = trim($value, ".");
}

exec("dig +short {$host} NS", $output, $return);
if($return > 0)
{
    exit($return);
}

array_walk($output, 'removeDot');
sort($output);

echo implode(", ", $output);
?>