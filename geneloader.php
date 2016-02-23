#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * LOVD scripts: Gene Loader
 *
 * (based on load_HGNC_data.php, created 2013-02-13, last modified 2015-10-08)
 * Created     : 2016-02-22
 * Modified    : 2016-02-22
 * Version     : 0.1
 * For LOVD    : 3.0-15
 *
 * Purpose     : To help the user automatically load a large number of genes into LOVD3, together with the desired
 *               transcripts, and optionally, the diseases.
 *               This script retrieves the list of genes from the HGNC and creates an LOVD3 import file format with the
 *               gene and transcript information. It checks on LOVD.nl whether or not to use LRG, NG or NC. It also
 *               queries Mutalyzer for the reference transcript's information, and puts these in the file, too.
 *
 * Copyright   : 2004-2016 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmer  : Ing. Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>
 *
 *
 * This file is part of LOVD.
 *
 * LOVD is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * LOVD is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with LOVD.  If not, see <http://www.gnu.org/licenses/>.
 *
 *************/

if (isset($_SERVER['HTTP_HOST'])) {
    die('Please run this script through the command line.' . "\n");
}

$_CONFIG = array(
    'version' => '0.1',
    'hgnc_file' => 'HGNC_download.txt',
    'hgnc_base_url' => 'http://www.genenames.org/cgi-bin/download',
    'hgnc_col_var_name' => 'col',
    'hgnc_other_vars' => 'status=Approved&status_opt=2&where=&order_by=gd_app_sym_sort&format=text&limit=&hgnc_dbtag=on&submit=submit',
    // We ignore genes from the following locus groups:
    'bad_locus_groups' => array(
        'phenotype', // No transcripts.
        'withdrawn', // Do not exist anymore.
    ),
    // We ignore genes from the following locus types (most of these are in group "other"):
    'bad_locus_types' => array(
        'endogenous retrovirus',  // From group "other", none of them work (verified).
        'fragile site',           // From group "other", none of them work (verified).
        'immunoglobulin gene',    // From group "other", none of them work (verified).
        'region',                 // From group "other", none of them work (verified).
        'transposable element',   // From group "other", none of them work (verified).
        'unknown',                // From group "other", none of them work (verified).
        'virus integration site', // From group "other", none of them work (verified).
        'immunoglobulin pseudogene', // From group "pseudogene", none of them work (verified).
    ),
    'user' => array(
        // Variables we will be asking the user.
        'lovd_path' => '',
        'update_hgnc' => 'n',
        'gene_list' => 'all',
        'transcript_list' => 'best',
        'genes_to_ignore' => 'genes_to_ignore.txt',
    ),
    'hgnc_columns' => array(
        'gd_hgnc_id' => 'HGNC ID',
        'gd_app_sym' => 'Approved Symbol',
        'gd_app_name' => 'Approved Name',
        'gd_locus_type' => 'Locus Type',
        'gd_locus_group' => 'Locus Group',
        'gd_pub_chrom_map' => 'Chromosome',
        'gd_pub_eg_id' => 'Entrez Gene ID', // Curated by the HGNC, not the other one.
        'gd_pub_refseq_ids' => 'RefSeq IDs', // Curated by the HGNC.
        'md_mim_id' => 'OMIM ID(supplied by OMIM)',
        'md_refseq_id' => 'RefSeq(supplied by NCBI)', // Downloaded from external sources.
    ),
    'lovd_gene_columns' => array(
        'id',
        'name',
        'chromosome',
        'chrom_band',
        'refseq_genomic',
        'refseq_UD',
        'id_hgnc',
        'id_entrez',
        'id_omim',
    ),
    'lovd_transcript_columns' => array(
        'id',
        'geneid',
        'name',
        'id_mutalyzer',
        'id_ncbi',
        'id_protein_ncbi',
        'position_c_mrna_start',
        'position_c_mrna_end',
        'position_c_cds_end',
        'position_g_mrna_start',
        'position_g_mrna_end',
    ),
);





function lovd_verifySettings ($sKeyName, $sMessage, $sVerifyType, $options)
{
    // Based on a function provided by Ileos.nl in the interest of Open Source.
    // Check if settings match certain input.
    global $_CONFIG;

    switch($sVerifyType) {
        case 'array':
            $aOptions = $options;
            if (!is_array($aOptions)) {
                return false;
            }
            break;

        case 'int':
            // Integer, options define a range in the format '1,3' (1 to 3) or '1,' (1 or higher).
            $aRange = explode(',', $options);
            if (!is_array($aRange) ||
                ($aRange[0] === '' && $aRange[1] === '') ||
                ($aRange[0] !== '' && !ctype_digit($aRange[0])) ||
                ($aRange[1] !== '' && !ctype_digit($aRange[1]))) {
                return false;
            }
            break;
    }

    while (true) {
        print('  ' . $sMessage .
            (empty($_CONFIG['user'][$sKeyName])? '' : ' [' . $_CONFIG['user'][$sKeyName] . ']') . ' : ');
        $sInput = trim(fgets(STDIN));
        if (!strlen($sInput) && !empty($_CONFIG['user'][$sKeyName])) {
            $sInput = $_CONFIG['user'][$sKeyName];
        }

        switch ($sVerifyType) {
            case 'array':
                $sInput = strtolower($sInput);
                if (in_array($sInput, $aOptions)) {
                    $_CONFIG['user'][$sKeyName] = $sInput;
                    return true;
                }
                break;

            case 'int':
                $sInput = (int) $sInput;
                // Check if input is lower than minimum required value (if configured).
                if ($aRange[0] !== '' && $sInput < $aRange[0]) {
                    break;
                }
                // Check if input is higher than maximum required value (if configured).
                if ($aRange[1] !== '' && $sInput > $aRange[1]) {
                    break;
                }
                $_SETT[$sKeyName] = $sInput;
                return true;

            case 'file':
            case 'lovd_path':
            case 'path':
                // Always accept the default or the given options.
                if ($sInput == $_CONFIG['user'][$sKeyName] ||
                    $sInput === $options ||
                    (is_array($options) && in_array($sInput, $options))) {
                    $_CONFIG['user'][$sKeyName] = $sInput; // In case an option was chosen that was not the default.
                    return true;
                }
                if (in_array($sVerifyType, array('lovd_path', 'path')) && !is_dir($sInput)) {
                    print('    Given path is not a directory.' . "\n");
                    break;
                } elseif (!is_readable($sInput)) {
                    print('    Cannot read given path.' . "\n");
                    break;
                }

                if ($sVerifyType == 'lovd_path') {
                    if (!file_exists($sInput . '/config.ini.php')) {
                        if (file_exists($sInput . '/src/config.ini.php')) {
                            $sInput .= '/src';
                        } else {
                            print('    Cannot locate config.ini.php in given path.' . "\n" .
                                  '    Please check that the given path is a correct path to an LOVD installation.' . "\n");
                            break;
                        }
                    }
                    if (!is_readable($sInput . '/config.ini.php')) {
                        print('    Cannot read configuration file in given LOVD directory.' . "\n");
                        break;
                    }
                    // We'll set everything up later, because we don't want to
                    // keep the $_DB open for as long as the user is answering questions.
                }
                $_CONFIG['user'][$sKeyName] = $sInput;
                return true;

            default:
                return false;
        }
    }

    return false; // We'd actually never get here.
}





// Obviously, we could be running for quite some time.
set_time_limit(0);





print('Gene Loader v' . $_CONFIG['version'] . '.' . "\n");

// Verify settings with user.
if (!lovd_verifySettings('lovd_path', 'Path of LOVD installation to load data into', 'lovd_path', '')) {
    die('  Failed to get LOVD path.' . "\n");
}
lovd_verifySettings('update_hgnc', 'Download new HGNC data if file already available? (Yes/No)', 'array', array('yes', 'no', 'y', 'n'));
lovd_verifySettings('gene_list', 'File containing the gene symbols that you want created,
    or just press enter to create all genes', 'file', '');
lovd_verifySettings('transcript_list', 'File containing the transcripts that you want created,
    type \'all\' to have all transcripts created,
    or just press enter to let LOVD pick the best transcript per gene', 'file', array('all', 'best'));
lovd_verifySettings('genes_to_ignore', 'File that we can read and write to,
    containing gene symbols to ignore to speed up consecutive runs', 'file', '');





// Check gene and transcript files and file formats.
$aGenesToCreate = $aTranscriptsToCreate = array();

// Gene list.
if (is_readable($_CONFIG['user']['gene_list'])) {
    // Gene list argument is a file.
    $aFile = file($_CONFIG['user']['gene_list']);
    // Loop through the file to check it.
    foreach ($aFile as $nLine => $sLine) {
        $sLine = trim($sLine);
        if (!$sLine || $sLine{0} == '#') {
            continue;
        }
        if (!preg_match('/^[A-Z][A-Za-z0-9_@-]+$/', $sLine)) {
            $nLine ++;
            die('  Can not read gene list file on line ' . $nLine . ', not a valid gene symbol format.' . "\n");
        }
        $aGenesToCreate[$sLine] = 1; // Using genes as keys speeds up the lookup process a lot.
    }
    print('  Read ' . count($aGenesToCreate) . ' genes to create.' . "\n");
}

// Transcript list.
if (is_readable($_CONFIG['user']['transcript_list'])) {
    // Gene list argument is a file.
    $aFile = file($_CONFIG['user']['transcript_list']);
    // Loop through the file to check it.
    foreach ($aFile as $nLine => $sLine) {
        $sLine = trim($sLine);
        if (!$sLine || $sLine{0} == '#') {
            continue;
        }
        if (!preg_match('/^[NX][MR]_[0-9]{6,9}(\.[0-9]+)?$/', $sLine)) {
            $nLine ++;
            die('  Can not read transcript list file on line ' . $nLine . ', not a valid transcript format.' . "\n");
        }
        @list($sIDWithoutVersion, $nVersion) = explode('.', $sLine); // Version is not mandatory.
        if (!isset($aTranscriptsToCreate[$sIDWithoutVersion])) {
            $aTranscriptsToCreate[$sIDWithoutVersion] = array();
        }
        $aTranscriptsToCreate[$sIDWithoutVersion][] = $nVersion;
    }
    print('  Read ' . count($aTranscriptsToCreate) . ' transcripts to create.' . "\n");
}



// Download HGNC data first. We might not be able to send the full query to the HGNC,
//  so better just download the whole thing and loop through it.
// In case it already exists, check if we need to download it again.
if (!file_exists($_CONFIG['hgnc_file']) || in_array($_CONFIG['user']['update_hgnc'], array('y', 'yes'))) {
    // Construct link.
    $sURL = $_CONFIG['hgnc_base_url'] . '?' . $_CONFIG['hgnc_col_var_name'] . '=' . implode('&' . $_CONFIG['hgnc_col_var_name'] . '=', array_keys($_CONFIG['hgnc_columns'])) . '&' . $_CONFIG['hgnc_other_vars'];
    $f = fopen($_CONFIG['hgnc_file'], 'w');
    if ($f === false) {
        die('  Could not create new HGNC data file.' . "\n");
    }
    print('  Downloading HGNC data... ');
    $sHGNCData = @file_get_contents($sURL);
    if (!$sHGNCData) {
        die('Failed.
    Could not download HGNC data. URL used:
      ' . $sURL . "\n");
    }
    if (!fputs($f, $sHGNCData)) {
        die('Failed.
    Could not write to HGNC data file.' . "\n");
    }
    print('OK!' . "\n");
}
print("\n");



// Find LOVD installation, run it's inc-init.php to get DB connection, initiate $_SETT, etc.
define('ROOT_PATH', $_CONFIG['user']['lovd_path'] . '/');
define('FORMAT_ALLOW_TEXTPLAIN', true);
$_GET['format'] = 'text/plain';
// To prevent notices when running inc-init.php.
$_SERVER = array_merge($_SERVER, array(
    'HTTP_HOST' => 'localhost',
    'REQUEST_URI' => '/',
    'QUERY_STRING' => '',
    'REQUEST_METHOD' => 'GET',
));
// If I put a require here, I can't nicely handle errors, because PHP will die if something is wrong.
// However, I need to get rid of the "headers already sent" warnings from inc-init.php.
// So, sadly if there is a problem connecting to LOVD, the script will die here without any output whatsoever.
ini_set('display_errors', '0');
ini_set('log_errors', '0'); // CLI logs errors to the screen, apparently.
require ROOT_PATH . 'inc-init.php';



// Start checking and reading out the HGNC file.
print('  Reading HGNC data...' . "\n");

$aHGNCFile = file($_CONFIG['hgnc_file'], FILE_IGNORE_NEW_LINES);
if ($aHGNCFile === false) {
    die('  Could not open the HGNC data file.' . "\n");
} else {
    print('  Checking file header... ');
}

// We need very selective info from the HGNC.
// Check if all the columns we need are there, and check the order in which they appear.
$aColumnsInFile = explode("\t", $aHGNCFile[0]);
$aHGNCColumns = array(); // array(0 => 'gd_hgnc_id', 1 => 'gd_app_sym', ...);

// Determine the correct keys for the columns we want.
foreach ($aColumnsInFile as $nKey => $sName) {
    if ($sCol = array_search($sName, $_CONFIG['hgnc_columns'])) {
        // We need this column!
        $aHGNCColumns[$nKey] = $sCol;
    }
}

if (count($aHGNCColumns) < count($_CONFIG['hgnc_columns'])) {
    // We didn't find all needed columns!
    die('Failed.
    Could not find all needed columns, please check the file\'s format,
      or redownload the file.' . "\n");
} else {
    print('OK!
    Retrieving additional resources... ');
    unset($aHGNCFile[0]);
}
$nHGNCGenes = count($aHGNCFile);

// Get list of LRGs and NGs to determine the genomic refseq of the genes.
$aLRGFile = lovd_php_file('http://www.lovd.nl/mirrors/lrg/LRG_list.txt');
unset($aLRGFile[0], $aLRGFile[1]);
$aLRGs = array();
foreach ($aLRGFile as $sLine) {
    $aLine = explode("\t", $sLine);
    $aLRGs[$aLine[1]] = $aLine[0];
}
$aNGFile = lovd_php_file('http://www.lovd.nl/mirrors/ncbi/NG_list.txt');
unset($aNGFile[0], $aNGFile[1]);
$aNGs = array();
foreach ($aNGFile as $sLine) {
    $aLine = explode("\t", $sLine);
    $aNGs[$aLine[0]] = $aLine[1];
}
if (!count($aLRGs) || !count($aNGs)) {
    die('Failed!
    Could not retrieve LRG and NG resources.' . "\n");
} else {
    print('OK!
    Resources stored, loading ignore list... ');
}

// See if we can file genes to ignore.
$aGenesToIgnore = array();
$bGenesToIgnoreIsEmpty = false; // If it's empty, we'll write an informative header.
$bWroteToGenesFile = false; // The first gene we write there, will be a header with the current date.
if (file_exists($_CONFIG['user']['genes_to_ignore'])) {
    if (!is_readable($_CONFIG['user']['genes_to_ignore'])) {
        die('present, but can not open the file.
    Please check the permissions and try again.' . "\n");
    } else {
        $aFile = file($_CONFIG['user']['genes_to_ignore']);
        if (!$aFile) {
            $bGenesToIgnoreIsEmpty = true;
        }
        // Loop through the file to check it.
        foreach ($aFile as $nLine => $sLine) {
            $sLine = trim($sLine);
            if (!$sLine || $sLine{0} == '#') {
                continue;
            }
            $aGenesToIgnore[$sLine] = 1; // Using genes as keys speeds up the lookup process a lot.
        }
        print('OK!');
    }
} else {
    print('None exists yet.');
}
print('  Preparing gene ignore list for appending... ');
$fGenesToIgnore = fopen($_CONFIG['user']['genes_to_ignore'], 'a');
if ($fGenesToIgnore === false) {
    die('Failed!
    Could not append to file, please check its permissions.' . "\n");
} else {
    print('OK!
  Starting the run...' . "\n");
}

// Append header to $fGenesToIgnore, if empty.
if ($bGenesToIgnoreIsEmpty) {
    fputs($fGenesToIgnore,
        '# These genes were ignored, because no reference sequence or transcripts could be found.' . "\r\n" .
        '# Keeping this list speeds up the process a lot.' . "\r\n");
}








////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
?>