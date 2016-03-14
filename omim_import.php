<?php

// MG-LOVD Custom Script
// This script will take the morbidmap.txt file located on the OMIM ftp (http://www.omim.org/downloads) and import it as a phenotype .
// Created 2016-03-11 by Anthony Marty.

define('ROOT_PATH', '../LOVD_plus/src/');
require ROOT_PATH . 'inc-init.php';

//$aDBGenes = $_DB->query('SELECT id, chromosome, id_omim  FROM ' . TABLE_GENES)->fetchAllAssoc();
$sql = $_DB->query('SELECT id, chromosome, id_omim  FROM ' . TABLE_GENES);
$aDBGenes = array_map('reset', $sql->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC));

$sql = $_DB->query('SELECT id_omim, id, chromosome FROM ' . TABLE_GENES . ' WHERE id_omim');
$aDBOMIM = array_map('reset', $sql->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC));

// We are using the morbidmap.txt file from OMIM
// http://data.omim.org/downloads/uIGJgQ5NQdiihMvjiWyZgQ/morbidmap.txt
// The above URL is valid until 2016-03-11 and then a new request will need to be made
$sInputFile = '../morbidmap.txt';
$aOMIMFile = file($sInputFile, FILE_IGNORE_NEW_LINES);

// Put any characters that need to be removed in this array
$aRemoveChars = array(' (1)',' (2)',' (3)',' (4)', '}', '{', '?', '[', ']');

$aOMIMData = array();

$aInsertData = array();
$aInsertG2D = array();

if (!count($aOMIMFile)) {
    print('Error opening ' . $sInputFile . '. Please make sure to download it from the OMIM website http://www.omim.org/downloads.');
    exit;
}

foreach ($aOMIMFile as $nKey => $sLine) {

    $aData = array();
    $aData['error'] = false;


    // Detect comment lines
    // TODO Do we need to valide that this is the correct file format before we proceed?
    if (substr($sLine,0,1) == '#') {
        continue;
    }

    $aLine = explode("\t",$sLine);
    $aGenes = explode(', ',$aLine[1]);

    $aData['gene_id_omim'] = $aLine[2];
    $aData['genes'] = explode(', ',$aLine[1]);

    // Sometimes the chromosome is formatted as Chr.11 and other times it is formatted as 11q13.32
    if (substr($aLine[3],0,3) == 'Chr') {
        preg_match('/(\d+|X|Y)$/', $aLine[3], $aRegs);
    } else {
        preg_match('/^(\d+|X|Y)\w/', $aLine[3], $aRegs);
    }

    // Check to see if we were able to find a chromosome
    // TODO Validate that we have a valid chromosome
    if(empty($aRegs[1])) {
        $aData['error'] = true;
        $aData['chr'] = null;
    } else {
        $aData['chr'] = $aRegs[1];
    }

    if (!isset($aData['chr'])) {
        print ('Undefined index for ' . $sLine);
    }

    $aData['phenotype'] = str_replace($aRemoveChars,'',preg_replace('/, \d{6}+/','', $aLine[0]));

    // Find the phenotype OMIM ID
    preg_match('/ (\d{6}) /', $aLine[0], $aRegs);
    if(empty($aRegs[1])) {
        $aData['error'] = true;
        $aData['phenotype_id_omim'] = null;
        // TODO what do we want to do if there is no phenotype OMIM ID? Currently we are ignoring this line as we need OMIM IDs to identify unique phenotypes?
    } else {
        $aData['phenotype_id_omim'] = $aRegs[1];
    }

    // First try to see if there is an exact match with the OMIM ID, chromosome and gene symbol
    if (!empty($aDBOMIM[$aData['gene_id_omim']]) && in_array($aDBOMIM[$aData['gene_id_omim']]['id'], $aData['genes']) && $aDBOMIM[$aData['gene_id_omim']]['chromosome'] == $aData['chr']) {
        // TODO Need to check for duplicates whenever we assign the gene ID we will be using.
        $aData['db_gene'] = $aDBOMIM[$aData['gene_id_omim']]['id'];
    } else {
        // Loop through the gene symbols to see if we find a match in the database
        foreach ($aData['genes'] as $nKey => $sGene) {
            if (!empty($aDBGenes[$sGene]) && $aDBGenes[$sGene]['chromosome'] == $aData['chr'] ) {
                $aData['db_gene'] = $sGene;
            }
        }

        if (!isset($aData['db_gene'])) {
            $aData['error'] = true;
//            print('Can not find gene for this line: ' . $sLine . '<BR>');
        } else {
        }
    }


if ($aData['error']) {
//    var_dump($sLine, $aData);
//    print('<HR>');
//    var_dump($sChr, $aRegs[1]);

} else {
    if (empty($aInsertData[$aData['phenotype_id_omim']])) {
        $aInsertData[$aData['phenotype_id_omim']] = array('phenotype' => $aData['phenotype'], 'genes' => array($aData['db_gene']));
    } else {

        // Check if the name of the phenotype is shorter than the existing name and if so then use it
        if (strlen($aData['phenotype']) < strlen($aInsertData[$aData['phenotype_id_omim']]['phenotype'])) {
            $aInsertData[$aData['phenotype_id_omim']]['phenotype'] = $aData['phenotype'];
        }

        // Check if this gene has already been added and if not then add it
        if (!in_array($aData['db_gene'], $aInsertData[$aData['phenotype_id_omim']]['genes'])) {
            $aInsertData[$aData['phenotype_id_omim']]['genes'][] = $aData['db_gene'];
        }
    }

}





//    if ($nKey > 700) {
//        break;
//    }

}

// $aInsertData should now contain unique phenotype with their own OMIM IDs as well as Unique genes associated with them.

// Load up the existing disease and gen2dis tables in the DB
$aDiseasesInLOVD = $_DB->query('SELECT `id_omim`, `id` FROM ' . TABLE_DISEASES . ' WHERE id_omim IS NOT NULL ')->fetchAllCombine();
$aGen2DisInLOVD = $_DB->query('SELECT CONCAT(geneid, diseaseid), NULL FROM ' . TABLE_GEN2DIS)->fetchAllCombine();


$qDiseases = $_DB->prepare('INSERT INTO ' . TABLE_DISEASES . '(`name`,`id_omim`,`created_by`,`created_date`) VALUES (?,?,?,?)');
$qGen2Dis = $_DB->prepare('INSERT INTO ' . TABLE_GEN2DIS . '(`geneid`,`diseaseid`) VALUES (?,?)');

$aDiseaseForLOVD = array();
$aGen2DisForLOVE = array();
$nDiseasesCreated = 0;
$nGen2DisCreated = 0;

// Loop through each of these $aInsertData records
foreach ($aInsertData as $nOMIMID => $OMIMEntry) {
    // Check to see if the phenotype already exists within the DB, if so then get the id and continue otherwise insert and return the new id
    if (isset($aDiseasesInLOVD[$nOMIMID])) {
        $nID = $aDiseasesInLOVD[$nOMIMID];
    } else {
        // Setup the disease data to insert
        $aDiseaseForLOVD = array(
            'name' => $OMIMEntry['phenotype'],
            'id_omim' => $nOMIMID,
            'created_by' => 0,
            'created_date' => date('Y-m-d H:i:s'),
        );
        // Insert the new disease and return the new disease ID
        $qDiseases->execute(array_values($aDiseaseForLOVD));
        $nID = $_DB->lastInsertId();
        $nDiseasesCreated++;
    }

    // Loop through each of the genes and check to see if it is already in the DB, if so then ignore otherwise insert
    foreach ($OMIMEntry['genes'] as $nKey => $sGene) {
        if (!array_key_exists($sGene . $nID, $aGen2DisInLOVD)) {
            $aGen2DisForLOVE = array(
                'geneid' => $sGene,
                'diseaseid' => $nID,
            );
            $qGen2Dis->execute(array_values($aGen2DisForLOVE));
            $nGen2DisCreated++;
        }
    }


//    if ($nDiseasesCreated > 50) {
//        break;
//    }

}

//var_dump($aInsertData[211980], $aInsertData[202400], $aInsertData); // TODO Just debug info, REMOVE IN PRODUCTION
//var_dump($aDiseasesInLOVD);

print('<BR>DONE! Processed ' . count($aOMIMFile) . ' lines in the ' . $sInputFile . ' file, inserted ' . $nDiseasesCreated . ' new diseases and added ' . $nGen2DisCreated . ' links from genes to diseases.');


?>

