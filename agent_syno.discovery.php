<?php

function tfgc($path)
{
    return trim(file_get_contents($path));
}

function discovery_disks()
{
    $disks = array();
    foreach(glob('/sys/block/sd*') as $path)
    {
        $entry = array();
        $entry['{#NAME}'] = basename($path);
        $entry['{#VENDOR}'] = tfgc($path . '/device/vendor');
        $entry['{#MODEL}'] = tfgc($path . '/device/model');
        $entry['{#REV'] = tfgc($path . '/device/rev');
        $entry['{#SIZE}'] = tfgc($path . '/size');

        $disks[] = $entry;
    }

    echo json_encode(array('data' => $disks));
}

function discovery_arrays()
{
    $arrays = array();
    foreach(glob('/sys/block/md*') as $path)
    {
        $entry = array();
        $entry['{#NAME}'] = basename($path);
        $entry['{#NUMRAIDDISKS}'] = tfgc($path . 'md/raid_disks');
        $entry['{#LEVEL}'] = tfgc($path . 'md/level');
        $slaves = array();
        foreach(glob($path . '/slaves') as $slave)
        {
            $slaves[] = basename($slave);
        }
        $entry['{#MEMBERS}'] = implode(', ', $slaves);
        
        $arrays[] = $entry;
    }
    
    echo json_encode(array('data' => $arrays));
}

discovery_arrays();
?>