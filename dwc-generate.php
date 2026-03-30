<?php

use Elastic\Elasticsearch\ClientBuilder;

require 'vendor/autoload.php';

class BuildDwcHelper {

  /**
   * Website ID.
   *
   * @var int
   */
  private $websiteID;

  /**
   * Website password.
   *
   * @var string
   */
  private $websitePassword;

  /**
   * URL for warehouse web-service access.
   *
   * @var string
   */
  private $warehouseUrl;

  /**
   * ID of the main taxonomic checklist.
   *
   * @var int
   */
  private $masterChecklistId;


  /**
   * Configuration for current export.
   *
   * @var object
   */
  private $conf;

  /**
   * Configuration as loaded.
   *
   * @var object
   */
  private $confAsLoaded;

  /**
   * Array of metadata for the output data files.
   *
   * List of files to output, each an array containing type (Occurrence or
   * Event), filename, columns (array of DwC terms). The first file is the core
   * file, any extra files described are extensions.
   */
  private array $dataFiles;

  /**
   * Auth tokens.
   *
   * @var array
   */
  private array $readAuth;

  /**
   * Constructor loads and checks config.
   *
   * @param string $configFileName
   *   Name of the config file name with relative or absolute path.
   */
  public function __construct($configFileName) {
    echo "\n-Starting extraction\n";
    $this->loadServerConfig();
    try {
      $this->loadConfig($configFileName);
    }
    catch (Exception $e) {
      die("Error loading \"$configFileName\"\n" . $e->getMessage());
    }
    $this->readAuth = $this->getReadAuth();
  }

  /**
   * Load the warehouse configuration.
   */
  private function loadServerConfig() {
    if (!file_exists('config/warehouse.json')) {
      throw new Exception('Configuration file config/warehouse.json not found');
    }
    $configFileContents = file_get_contents('config/warehouse.json');
    if (empty(trim($configFileContents))) {
      throw new Exception('Empty configuration file');
    }
    $warehouseConf = json_decode($configFileContents);
    $this->websiteID = (int) $warehouseConf->website_id;
    $this->websitePassword = $warehouseConf->website_password;
    $this->warehouseUrl = $warehouseConf->warehouse_url;
    $this->masterChecklistId = (int) $warehouseConf->master_checklist_id;
  }

  /**
   * Load the export configuration.
   *
   * @param string $configFileName
   *   Configuration file name.
   */
  private function loadConfig($configFileName) {
    if (!file_exists($configFileName)) {
      throw new Exception("Configuration file $configFileName not found");
    }
    $configFileContents = file_get_contents($configFileName);
    if (empty(trim($configFileContents))) {
      throw new Exception("Empty configuration file");
    }
    $configDecoded = json_decode($configFileContents, TRUE);
    if (!$configDecoded) {
      throw new Exception(message: "Invalid configuration file content, possibly a JSON syntax error");
    }
    $this->confAsLoaded = array_merge([
      'options' => [],
    ], $configDecoded);
    // If repeatExport not configured, set up a default so a single file is
    // export using the base config.
    if (empty($this->confAsLoaded['repeatExport'])) {
      $this->confAsLoaded['repeatExport'] = [
        [],
      ];
    }
    echo "Config file \"$configFileName\" loaded\n";
    // If the config file has overrides for warehouse connection details, apply
    // them.
    if (!empty($this->confAsLoaded['website_id'])) {
      $this->websiteID = (int) $this->confAsLoaded['website_id'];
    }
    if (!empty($this->confAsLoaded['website_password'])) {
      $this->websitePassword = $this->confAsLoaded['website_password'];
    }
    if (!empty($this->confAsLoaded['warehouse_url'])) {
      $this->warehouseUrl = $this->confAsLoaded['warehouse_url'];
    }
    if (!empty($this->confAsLoaded['master_checklist_id'])) {
      $this->masterChecklistId = (int) $this->confAsLoaded['master_checklist_id'];
    }
  }

  private function initConfig($configFileName) {
    // Apply conventional defaults.
    $baseName = pathinfo($configFileName, PATHINFO_FILENAME);
    if (empty($this->conf['xmlFilesInDir']) && is_dir("metadata/$baseName")) {
      $this->conf['xmlFilesInDir'] = "metadata/$baseName";
    }
    $this->conf = array_merge([
      'basisOfRecord' => 'HumanObservation',
      'basisOfRecordDna' => 'MaterialSample',
      'batchSize' => 1000,
      'defaultLicenceCode' => '',
      'eventIdPrefix' => '',
      'occurrenceIdPrefix' => '',
      'outputFile' => 'exports/' . preg_replace('/[^a-z0-9]/', '_', strtolower($baseName)) . '.zip',
      'scrollKeepAlive' => '2m',
      'scrollRetryCount' => 1,
      'scrollRetryDelayMs' => 500,
    ], $this->conf);
    if (!empty($this->conf['filterId'])) {
      $this->loadFilterIntoConfig();
    }
    // Apply shortcut filters for survey ID and higher geography.
    if (!empty($this->conf['surveyId'])) {
      $this->conf['query']['bool']['filter'][] = ['term' => ['metadata.survey.id' => $this->conf['surveyId']]];
    }
    if (!empty($this->conf['higherGeographyId'])) {
      $this->conf['query']['bool']['filter'][] = [
        'nested' => [
          'path' => 'location.higher_geography',
          'query' => [
            'term' => ['location.higher_geography.id' => $this->conf['higherGeographyId']],
          ],
        ],
      ];
    }
  }

  /**
   * Validates parameters in the config file.
   *
   * @throws Exception
   *   Throws exceptions where problems found.
   */
  private function validateConfig() {
    if (empty($this->conf['elasticsearchHost'])) {
      throw new Exception("Missing elasticsearchHost setting in configuration");
    }
    if (empty($this->conf['index'])) {
      throw new Exception("Missing index setting in configuration");
    }
    if (empty($this->conf['outputType'])) {
      throw new Exception("Missing outputType setting in configuration");
    }
    if (!in_array($this->conf['outputType'], ['dwca', 'csv'])) {
      throw new Exception("Unsupported outputType setting in configuration");
    }
    if (empty($this->conf['outputFile'])) {
      throw new Exception("Missing outputFile setting in configuration");
    }
    if (empty($this->conf['query']) && empty($this->conf['filterId'])) {
      throw new Exception("Invalid configuration file - either a query or a filterId entry is required.");
    }
    if ($this->conf['outputType'] === 'dwca') {
      if (!file_exists($this->conf['outputFile']) && !isset($this->conf['xmlFilesInDir'])) {
        throw new Exception('Darwin Core Archive output file should already exist, or additional XML files specified in folder identified by xmlFilesInDir setting.');
      }
      if (isset($this->conf['xmlFilesInDir'])) {
        if (!is_dir($this->conf['xmlFilesInDir'])) {
          throw new Exception($this->conf['xmlFilesInDir'] . ' directory specified in xmlFilesInDir config setting does not exist');
        }
        if (!file_exists($this->conf['xmlFilesInDir'] . DIRECTORY_SEPARATOR . 'eml.xml')) {
          throw new Exception('EML file missing: ' . $this->conf['xmlFilesInDir'] . DIRECTORY_SEPARATOR . 'eml.xml');
        }
      }
    }
    if (!file_exists($this->conf['xmlFilesInDir'] . DIRECTORY_SEPARATOR . 'meta.xml')) {
      throw new Exception('Metadata file missing: ' . $this->conf['xmlFilesInDir'] . DIRECTORY_SEPARATOR . 'meta.xml');
    }
    if (empty($this->conf['rightsHolder'])) {
      throw new Exception("Missing rightsHolder setting in configuration");
    }
    if (empty($this->conf['datasetName'])) {
      throw new Exception("Missing datasetName setting in configuration");
    }
    if (!empty($this->conf['surveyId']) && !preg_match('/^\d+$/', $this->conf['surveyId'])) {
      throw new Exception('The surveyId setting should be an integer.');
    }
    if (!empty($this->conf['higherGeographyId']) && !preg_match('/^\d+$/', $this->conf['higherGeographyId'])) {
      throw new Exception('The higherGeographyId setting should be an integer containing a location ID.');
    }
    if (!preg_match('/^\d+$/', (string) $this->conf['batchSize']) || (int) $this->conf['batchSize'] < 1) {
      throw new Exception('The batchSize setting should be a positive integer.');
    }
    if (!preg_match('/^\d+[smhd]$/', (string) $this->conf['scrollKeepAlive'])) {
      throw new Exception('The scrollKeepAlive setting should be a duration like 30s, 2m, 1h or 1d.');
    }
    if (!preg_match('/^\d+$/', (string) $this->conf['scrollRetryCount'])) {
      throw new Exception('The scrollRetryCount setting should be a non-negative integer.');
    }
    if (!preg_match('/^\d+$/', (string) $this->conf['scrollRetryDelayMs'])) {
      throw new Exception('The scrollRetryDelayMs setting should be a non-negative integer (milliseconds).');
    }
  }

  /**
   * Load the meta.xml file.
   *
   * Loads the file which describes the event and/or occurrence output files
   * required.
   */
  function loadMetafile() {
    $this->dataFiles = [];
    $dom = new DOMDocument();
    $dom->loadXML(file_get_contents($this->conf['xmlFilesInDir'] . DIRECTORY_SEPARATOR . 'meta.xml'));
    $archive = $dom->getElementsByTagName('archive');
    if (count($archive) !== 1) {
      throw new Exception('Meta.xml file must have exactly 1 archive element.');
    }
    $core = $archive->item(0)->getElementsByTagName('core');
    if (count($core) !== 1) {
      throw new Exception('Meta.xml file must have exactly 1 core element in the archive element.');
    }
    $this->dataFiles[] = $this->getFileMetadataFromXml($core->item(0));
    $extensions = $archive->item(0)->getElementsByTagName('extension');
    if (count($extensions) > 0 && !in_array('id', $this->dataFiles[0]['extensionMappingColumns'])) {
      throw new Exception('Meta.xml file must describe an id column for the core file when extensions are present.');
    }
    foreach ($extensions as $extension) {
      $extMetadata = $this->getFileMetadataFromXml($extension);
      if (!in_array('coreid', $extMetadata['extensionMappingColumns'])) {
        throw new Exception('Meta.xml file must describe a coreid column for each extension.');
      }
      $this->dataFiles[] = $extMetadata;
    }
  }

  /**
   * Read the metadata needed for a data file from it's meta.xml element.
   *
   * @param DOMElement $el
   *   XML file element for the data file (core or extension).
   *
   * @return array
   *   Metadata, including the type (Occurrence or Event), filename and list
   *   of columns.
   */
  private function getFileMetadataFromXml(DOMElement $el): array {
    $rowType = $el->getAttribute('rowType');
    // We currently only support occurrence, event and DNA-derived data types
    // but this could be extended in future.
    if (!preg_match('/^http(s)?:\/\/rs.(tdwg|gbif).org\/(dwc\/terms|terms\/1\.0)\/(?P<type>(Occurrence|Event|DNADerivedData))$/', $rowType, $matches)) {
      throw new Exception('Unrecognised rowType given for the core element.');
    }
    $r = [
      'type' => $matches['type'],
      'columns' => [],
      'extensionMappingColumns' => [],
    ];
    // The filename in the metadata files locations elements are only used for
    // the components of a DwC-A export, or if there are multiple CSV files
    // specified.
    if ($this->conf['outputType'] === 'dwca' || count($this->dataFiles) > 1) {
      $r['filename'] = $el->getElementsByTagName('files')->item(0)->getElementsByTagName('location')->item(0)->textContent;
    }
    foreach ($el->childNodes as $pos => $childEl) {
      if (is_a($childEl, 'DOMElement')) {
        $index = (integer) $childEl->getAttribute('index') === '' ? $pos : $childEl->getAttribute('index');
        // Duplicate index is allowed, e.g. if <id> and <field term="eventID">
        // both at index 0 which clarifies the dual purpose of the column
        // (both an event ID and a core ID for linking to extensions).
        if ($childEl->nodeName === 'id' || $childEl->nodeName === 'coreid') {
          $r['columns'][$index] = $childEl->nodeName;
          // Track the presence of id and coreid separately to other columns so
          // their presence isn't overwritten when another column definition
          // has the same index.
          $r['extensionMappingColumns'][$index] = $childEl->nodeName;
        }
        elseif ($childEl->nodeName === 'field') {
          $r['columns'][$index] = $this->mapDwcTermToColumnName(basename($childEl->getAttribute('term')));
        }
      }
    }
    ksort($r['columns']);
    return $r;
  }

  /**
   * Convert a DwC term URI to the correct term/column title.
   *
   * For example, https://w3id.org/mixs/0000044 is the URI for target_gene
   * term, so a parameter of 0000044 is mapped to target_gene.
   *
   * @param string $term
   *   The base name extracted from a term's URI in the meta.xml field.
   *
   * @return string
   *   The mapped term.
   */
  private function mapDwcTermToColumnName($term) {
    $mappings = [
      '0000044' => 'target_gene',
      '0000014' => 'env_medium',
      '0000012' => 'env_broad_scale',
      '0000087' => 'otu_db',
      '0000086' => 'otu_seq_comp_appr',
      '0000085' => 'otu_class_appr',
      '0000013' => 'env_local_scale',
      '0000045' => 'target_subfragment',
    ];
    return $mappings[$term] ?? $term;
  }

  /**
   * Return true if an occurrence is valid and complete.
   *
   * Currently this is any occurrence with a taxonID.
   *
   * @param array $source
   *   Occurrence data from ES.
   *
   * @return bool
   *   True if valid and complete.
   */
  private function isOccurrenceValid(array $source): bool {
    return !empty($source['taxon']['taxon_id']);
  }

  /**
   * Performs the task of building an occurrences data file.
   *
   * @param array $fileMetadata
   *   Metadata for this file, including the columns to ouput, extracted from
   *   meta.xml.
   */
  private function buildOccurrenceFile(array $fileMetadata) {
    $params = [
      // Must exceed the time needed to process/write each batch.
      'scroll' => $this->conf['scrollKeepAlive'],
      'size'   => $this->conf['batchSize'],
      'index'  => $this->conf['index'],
      'body'   => [
        'query' => $this->conf['query'],
      ],
    ];
    $this->buildOutputFile($fileMetadata, $params, [$this, 'getOccurrenceRowData'], 'Occurrence');
  }

  /**
   * Performs the task of building an events data file.
   *
   * @param array $fileMetadata
   *   Metadata for this file, including the columns to ouput, extracted from
   *   meta.xml.
   */
  private function buildEventFile(array $fileMetadata) {
    if (empty($this->conf['eventIndex'])) {
      throw new Exception("Missing eventIndex setting in configuration");
    }
    $params = [
      // Must exceed the time needed to process/write each batch.
      'scroll' => $this->conf['scrollKeepAlive'],
      'size'   => $this->conf['batchSize'],
      'index'  => $this->conf['eventIndex'],
      'body'   => [
        'query' => $this->conf['eventQuery'] ?? $this->conf['query'],
      ],
    ];
    echo "Event columns: " . implode(', ', $fileMetadata['columns']) . "\n";
    $this->buildOutputFile($fileMetadata, $params, [$this, 'getEventRowData'], 'Event');
  }

  /**
   * Performs the task of building a DNA dervied data file.
   *
   * @param array $fileMetadata
   *   Metadata for this file, including the columns to ouput, extracted from
   *   meta.xml.
   */
  private function buildDNADerivedDataFile(array $fileMetadata) {
    $dnaQuery = array_merge($this->conf['query']);
    $dnaQuery['bool']['filter'][] = ['term' => ['occurrence.dna_derived' => TRUE]];
    $params = [
      // Must exceed the time needed to process/write each batch.
      'scroll' => $this->conf['scrollKeepAlive'],
      'size'   => $this->conf['batchSize'],
      'index'  => $this->conf['index'],
      'body'   => [
        'query' => $dnaQuery,
      ],
    ];
    $this->buildOutputFile($fileMetadata, $params, [$this, 'getDNADerivedDataRowData'], 'DNADerivedData');
  }

  private function buildOutputFile(array $fileMetadata, array $params, callable $rowDataCallback, $class) {
    $client = ClientBuilder::create()->setHosts([$this->conf['elasticsearchHost']])->build();
    // Execute the search.
    // The response will contain the first batch of documents
    // and a scroll_id.
    $response = $client->search($params)->asArray();
    $file = fopen($this->getOutputCsvFileName($fileMetadata), 'w');
    if ($file === FALSE) {
      throw new Exception('Unable to open output CSV file for writing: ' . $this->getOutputCsvFileName($fileMetadata));
    }
    fputcsv($file, $fileMetadata['columns']);
    $expectedHits = is_array($response['hits']['total'] ?? NULL)
      ? ($response['hits']['total']['value'] ?? NULL)
      : ($response['hits']['total'] ?? NULL);
    $scrollId = $response['_scroll_id'] ?? NULL;
    $pages = 0;
    $hitsSeen = 0;
    $writtenRows = 0;
    $skippedRows = 0;

    try {
      // Now we loop until the scroll "cursors" are exhausted.
      while (isset($response['hits']['hits']) && count($response['hits']['hits']) > 0) {
        $pages++;
        foreach ($response['hits']['hits'] as $hit) {
          $hitsSeen++;
          if ($class === 'Occurrence' && !$this->isOccurrenceValid($hit['_source'])) {
            $skippedRows++;
            continue;
          }
          $rowData = call_user_func($rowDataCallback, $hit['_source'], $fileMetadata);
          fputcsv($file, $rowData);
          $writtenRows++;
        }
        if (empty($scrollId)) {
          throw new Exception('Elasticsearch scroll response did not include a scroll_id; cannot continue pagination safely.');
        }
        // Execute a Scroll request and repeat.
        $response = $this->doScrollRequestWithRetry($client, $scrollId, $class, $pages);
        // When done, get the new scroll_id in case it changes.
        $scrollId = $response['_scroll_id'] ?? $scrollId;
        // Progress.
        echo '.';
      }
      echo "\n";
      echo "{$class} export stats: pages=$pages, hitsSeen=$hitsSeen, rowsWritten=$writtenRows, rowsSkipped=$skippedRows";
      if ($expectedHits !== NULL) {
        echo ", expectedHits=$expectedHits";
      }
      echo "\n";
      if ($expectedHits !== NULL && $hitsSeen < $expectedHits) {
        echo "WARNING: Fewer hits processed than expected. This can indicate expired scroll context; consider increasing scrollKeepAlive.\n";
      }
    }
    finally {
      if (!empty($scrollId)) {
        // Best-effort cleanup of scroll context.
        try {
          $client->clearScroll([
            'body' => [
              'scroll_id' => [$scrollId],
            ],
          ]);
        }
        catch (Exception $e) {
          echo "Warning: unable to clear scroll context: {$e->getMessage()}\n";
        }
      }
      fclose($file);
    }
  }

  /**
   * Execute a scroll request with limited retries for transient errors.
   *
   * @param mixed $client
   *   Elasticsearch client instance.
   * @param string $scrollId
   *   Current scroll ID.
   * @param string $class
   *   Export class being processed.
   * @param int $page
   *   Current page number.
   *
   * @return array
   *   Scroll response.
   */
  private function doScrollRequestWithRetry($client, string $scrollId, string $class, int $page): array {
    $maxRetries = (int) $this->conf['scrollRetryCount'];
    $retryDelayMs = (int) $this->conf['scrollRetryDelayMs'];
    $attempt = 0;
    while (TRUE) {
      try {
        return $client->scroll([
          'body' => [
            // Using our previously obtained _scroll_id.
            'scroll_id' => $scrollId,
            // Plus the same timeout window.
            'scroll' => $this->conf['scrollKeepAlive'],
          ],
        ])->asArray();
      }
      catch (Throwable $e) {
        if ($attempt >= $maxRetries) {
          throw new Exception("Scroll request failed after " . ($attempt + 1) . " attempt(s) for $class page $page: " . $e->getMessage(), 0, $e);
        }
        $attempt++;
        echo "\nWarning: scroll request failed for $class page $page. Retry $attempt of $maxRetries. Error: {$e->getMessage()}\n";
        if ($retryDelayMs > 0) {
          usleep($retryDelayMs * 1000);
        }
      }
    }
  }

  /**
   * Build the output dataset files described by meta.xml.
   */
  public function buildFiles($configFileName) {
    foreach ($this->confAsLoaded['repeatExport'] as $exportOverrideInfo) {
      $this->conf = array_merge($this->confAsLoaded, $exportOverrideInfo);
      $this->initConfig($configFileName);
      $this->validateConfig();
      $this->loadMetafile();
      echo 'Metafile ' . $this->conf['xmlFilesInDir'] . "/meta.xml loaded\n";
      foreach ($this->dataFiles as $fileMetadata) {
        if ($fileMetadata['type'] === 'Occurrence') {
          $this->buildOccurrenceFile($fileMetadata);
        }
        elseif ($fileMetadata['type'] === 'Event') {
          $this->buildEventFile($fileMetadata);
        }
        elseif ($fileMetadata['type'] === 'DNADerivedData') {
          $this->buildDNADerivedDataFile($fileMetadata);
        }
      }
      if ($this->conf['outputType'] === 'dwca') {
        echo "Preparing Darwin Core archive file\n";
        $this->updateDwcaFile();
      }
    }
    echo "OK\n";
  }

  /**
   * If the config specifies a filter ID, convert to an ES query.
   */
  private function loadFilterIntoConfig() {
    $filter = $this->getData([
      'table' => 'filter',
      'id' => $this->conf['filterId'],
    ]);
    $definition = json_decode($filter[0]['definition'], TRUE);
    $bool = [
      'filter' => [
        ['term' => ['metadata.confidential' => FALSE]],
        ['term' => ['metadata.trial' => FALSE]],
        ['term' => ['metadata.release_status' => 'R']],
        [
          'query_string' => [
            'query' => '((metadata.sensitivity_blur:B) OR (!metadata.sensitivity_blur:*))',
          ],
        ],
      ],
    ];
    // Grid system if output is to the NBN.
    if (in_array('useGridRefsIfPossible', $this->conf['options'])) {
      $bool['filter'][] = [
        'terms' => [
          'location.output_sref_system.keyword' => [
            'OSGB',
            'OSIE',
            'UTM30ED50',
          ],
        ],
      ];
    }
    $this->applyUserFiltersTaxonGroupList($definition, $bool);
    $this->applyUserFiltersTaxaTaxonList($definition, $bool);
    $this->applyUserFiltersTaxonMeaning($definition, $bool);
    $this->applyUserFiltersTaxaTaxonListExternalKey($definition, $bool);
    $this->applyUserFiltersTaxonRankSortOrder($definition, $bool);
    $this->applyFlagFilter('marine', $definition, $bool);
    $this->applyFlagFilter('freshwater', $definition, $bool);
    $this->applyFlagFilter('terrestrial', $definition, $bool);
    $this->applyFlagFilter('non_native', $definition, $bool);
    $this->applyUserFiltersSearchArea($definition, $bool);
    //$this->applyUserFiltersLocationName($definition, $bool);
    $this->applyUserFiltersIndexedLocationList($definition, $bool);
    //$this->applyUserFiltersIndexedLocationTypeList($definition, $bool, $readAuth);
    $this->applyUserFiltersDate($definition, $bool);
    //$this->applyUserFiltersWho($definition, $bool);
    //$this->applyUserFiltersOccId($definition, $bool);
    //$this->applyUserFiltersOccExternalKey($definition, $bool);
    //$this->applyUserFiltersSmpId($definition, $bool);
    $this->applyUserFiltersQuality($definition, $bool);
    $this->applyUserFiltersIdentificationDifficulty($definition, $bool);
    $this->applyUserFiltersRuleChecks($definition, $bool);
    $this->applyUserFiltersAutoCheckRule($definition, $bool);
    $this->applyUserFiltersHasPhotos($definition, $bool);
    $this->applyUserFiltersWebsiteList($definition, $bool);
    $this->applyUserFiltersSurveyList($definition, $bool);
    $this->applyUserFiltersImportGuidList($definition, $bool);
    $this->applyUserFiltersInputFormList($definition, $bool);
    $this->applyUserFiltersGroupId($definition, $bool);
    //$this->applyUserFiltersAccessRestrictions($definition, $bool);
    $this->applyUserFiltersTaxaScratchpadList($definition, $bool);
    $this->applySharingAgreement($bool);
    // Merge filter with any query specified in the config.
    $this->conf['query'] = $this->conf['query'] ?? [];
    $this->conf['query']['bool'] = $this->conf['query']['bool'] ?? [];

    foreach ($bool as $op => $filters) {
      $this->conf['query']['bool'][$op] = $this->conf['query']['bool'][$op] ?? [];
      $this->conf['query']['bool'][$op] = array_merge($this->conf['query']['bool'][$op], $filters);
    }
    unset($this->conf['filterId']);
  }

  /**
   * Works out the filter value and associated operation for a set of params.
   */
  private function getDefinitionFilter($definition, array $params) {
    foreach ($params as $param) {
      if (!empty($definition[$param])) {
        return [
          'value' => $definition[$param],
          'op' => empty($definition[$param . '_op']) ? FALSE : $definition[$param . '_op'],
        ];
      }
    }
    return [];
  }

  /**
   * Converts an Indicia filter definition taxon_group_list to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersTaxonGroupList(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, [
      'taxon_group_list',
      'taxon_group_id',
    ]);
    if (!empty($filter)) {
      $bool['filter'][] = [
        'terms' => ['taxon.group_id' => $this->safeExplodeCsvIntArray($filter['value'])],
      ];
    }
  }

  /**
   * Generic function to apply a taxonomy filter to ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   * @param string $filterField
   *   Name of the field to filter on ('id' or 'taxon_meaning_id').
   * @param string $filterValues
   *   Comma separated list of IDs to filter against.
   */
  private function applyTaxonomyFilter(array $definition, array &$bool, $filterField, $filterValues) {
    // Convert the IDs to external keys, stored in ES as taxon_ids.
    $taxonData = $this->get("$this->warehouseUrl/index.php/services/report/requestReport", [
      'report' => 'library/taxa/convert_ids_to_external_keys.xml',
      'reportSource' => 'local',
      $filterField => $filterValues,
      'master_checklist_id' => $this->masterChecklistId,
    ]);
    $keys = [];
    foreach ($taxonData as $taxon) {
      $keys[] = $taxon['external_key'];
    }
    $keys = array_unique($keys);
    $bool['filter'][] = ['terms' => ['taxon.higher_taxon_ids' => $keys]];
  }

  /**
   * Converts an Indicia filter definition taxa_taxon_list_list to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersTaxaTaxonList(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, [
      'taxa_taxon_list_list',
      'higher_taxa_taxon_list_list',
      'taxa_taxon_list_id',
      'higher_taxa_taxon_list_id',
    ]);
    if (!empty($filter)) {
      $this->applyTaxonomyFilter($definition, $bool, 'id', $filter['value']);
    }
  }

  /**
   * Converts an Indicia filter definition taxon_meaning_list to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersTaxonMeaning(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, [
      'taxon_meaning_list',
      'taxon_meaning_id',
    ]);
    if (!empty($filter)) {
      $this->applyTaxonomyFilter($definition, $bool, 'taxon_meaning_id', $filter['value']);
    }
  }

  /**
   * Converts an filter def taxa_taxon_list_external_key_list to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersTaxaTaxonListExternalKey(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, [
      'taxa_taxon_list_external_key_list',
    ]);
    if (!empty($filter)) {
      $bool['filter'][] = ['terms' => ['taxon.higher_taxon_ids' => $this->safeExplodeCsvIntArray($filter['value'])]];
    }
  }

  /**
   * Converts a filter definition taxon_rank_sort_order filter to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersTaxonRankSortOrder(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, ['taxon_rank_sort_order']);
    // Filter op can be =, >= or <=.
    if (!empty($filter)) {
      if ($filter['op'] === '=') {
        $bool['filter'][] = [
          'match' => [
            'taxon.taxon_rank_sort_order' => $filter['value'],
          ],
        ];
      }
      else {
        $gte = $filter['op'] === '>=' ? $filter['value'] : NULL;
        $lte = $filter['op'] === '<=' ? $filter['value'] : NULL;
        $bool['filter'][] = [
          'range' => [
            'taxon.taxon_rank_sort_order' => [
              'gte' => $gte,
              'lte' => $lte,
            ],
          ],
        ];
      }
    }
  }

  /**
   * Converts a filter definition flag filter to an ES query.
   *
   * @param string $flag
   *   Flag name, e.g. marine, terrestrial, freshwater, non_native.
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyFlagFilter($flag, array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, ["{$flag}_flag"]);
    // Filter op can be =, >= or <=.
    if (!empty($filter) && $filter['value'] !== 'all') {
      $bool['filter'][] = [
        'match' => [
          "taxon.$flag" => $filter['value'] === 'Y',
        ],
      ];
    }
  }

  /**
   * Converts an Indicia filter definition search_area to an ES query.
   *
   * For ES purposes, any location_list filter is modified to a searchArea
   * filter beforehand.
   *
   * @param string $definition
   *   WKT for the searchArea in EPSG:4326.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersSearchArea($definition, array &$bool) {
    if (!empty($definition['searchArea'])) {
      $bool['filter'][] = [
        'geo_shape' => [
          'location.geom' => [
            'shape' => $definition['searchArea'],
            'relation' => 'intersects',
          ],
        ],
      ];
    }
  }

  /**
   * Converts an Indicia filter definition indexed_location_list to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersIndexedLocationList(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, [
      'indexed_location_list',
      'indexed_location_id',
    ]);
    if (!empty($filter)) {
      $boolClause = !empty($filter['op']) && $filter['op'] === 'not in' ? 'must_not' : 'filter';
      $bool[$boolClause][] = [
        'nested' => [
          'path' => 'location.higher_geography',
          'query' => [
            'terms' => ['location.higher_geography.id' => $this->safeExplodeCsvIntArray($filter['value'])],
          ],
        ],
      ];
    }
  }

  /**
   * Converts an Indicia filter definition date filter to an ES query.
   *
   * Date range, year or date age filters supported. Support for recorded
   * (default), input, edited, verified dates. Age is supported as long as
   * format specifies age in minutes, hours, days, weeks, months or years.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersDate(array $definition, array &$bool) {
    $esFields = [
      'recorded' => 'event.date_start',
      'input' => 'metadata.created_on',
      'edited' => 'metadata.updated_on',
      'verified' => 'identification.verified_on',
    ];
    // Default to recorded date.
    $definition['date_type'] = empty($definition['date_type']) ? 'recorded' : $definition['date_type'];
    // Check to see if we have a year filter.
    $fieldName = $definition['date_type'] === 'recorded' ? "date_year" : "$definition[date_type]_date_year";
    if (!empty($definition[$fieldName]) && !empty($definition[$fieldName . '_op'])) {
      if ($definition[$fieldName . '_op'] === '=') {
        $bool['filter'][] = [
          'term' => [
            'event.year' => $definition[$fieldName],
          ],
        ];
      }
      else {
        $esOp = $definition[$fieldName . '_op'] === '>=' ? 'gte' : 'lte';
        $bool['filter'][] = [
          'range' => [
            'event.year' => [
              $esOp => $definition[$fieldName],
            ],
          ],
        ];
      }
    }
    else {
      // Check for other filters that work off the precise date fields.
      $dateTypes = [
        'from' => 'gte',
        'to' => 'lte',
        'age' => 'gte',
      ];
      foreach ($dateTypes as $type => $esOp) {
        $fieldName = $definition['date_type'] === 'recorded' ? "date_$type" : "$definition[date_type]_date_$type";
        if (!empty($definition[$fieldName])) {
          $value = $definition[$fieldName];
          // Convert date format.
          if (preg_match('/^(?P<d>\d{2})\/(?P<m>\d{2})\/(?P<Y>\d{4})$/', $value, $matches)) {
            $value = "$matches[Y]-$matches[m]-$matches[d]";
          }
          elseif ($type === 'age') {
            $value = 'now-' . str_replace(
              ['minute', 'hour', 'day', 'week', 'month', 'year', 's', ' '],
              ['m', 'H', 'd', 'w', 'M', 'y', '', ''],
              strtolower($value)
            );
          }
          $bool['filter'][] = [
            'range' => [
              $esFields[$definition['date_type']] => [
                $esOp => $value,
              ],
            ],
          ];
        }
      }
    }
  }

  /**
   * Converts an Indicia filter definition quality filter to an ES query.
   *
   * Note that option 'OV' (decision by other verifiers) is not supported.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersQuality(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, ['quality']);
    if (!empty($filter)) {
      $valueList = explode(',', $filter['value']);
      $defs = [];
      foreach ($valueList as $value) {
        switch ($value) {
          // Answered query.
          case 'A':
            $defs[] = [
              'term' => ['identification.query.keyword' => 'A'],
            ];
            break;

          // Plausible.
          case 'C3':
            $defs[] = [
              'bool' => [
                'filter' => [
                  ['term' => ['identification.verification_status' => 'C']],
                  ['term' => ['identification.verification_substatus' => 3]],
                ],
              ],
            ];
            break;

          // Queried.
          case 'D':
            $defs[] = [
              'term' => ['identification.query.keyword' => 'Q'],
            ];
            break;

          case 'P':
            $defs[] = [
              'bool' => [
                'filter' => [
                  ['term' => ['identification.verification_status' => 'C']],
                  ['term' => ['identification.verification_substatus' => 0]],
                ],
                'must_not' => [
                  ['exists' => ['field' => 'identification.query']],
                ],
              ],
            ];
            break;

          // Not accepted.
          case 'R':
            $defs[] = [
              'term' => ['identification.verification_status' => 'R'],
            ];
            break;

          case 'R4':
            $defs[] = [
              'bool' => [
                'filter' => [
                  ['term' => ['identification.verification_status' => 'R']],
                  ['term' => ['identification.verification_substatus' => 4]],
                ],
              ],
            ];
            break;

          case 'R5':
            $defs[] = [
              'bool' => [
                'filter' => [
                  ['term' => ['identification.verification_status' => 'R']],
                  ['term' => ['identification.verification_substatus' => 5]],
                ],
              ],
            ];
            break;

          // Accepted.
          case 'V':
            $defs[] = ['term' => ['identification.verification_status' => 'V']];
            break;

          case 'V1':
            $defs[] = [
              'bool' => [
                'filter' => [
                  ['term' => ['identification.verification_status' => 'V']],
                  ['term' => ['identification.verification_substatus' => 1]],
                ],
              ],
            ];
            break;

          case 'V2':
            $defs[] = [
              'bool' => [
                'filter' => [
                  ['term' => ['identification.verification_status' => 'V']],
                  ['term' => ['identification.verification_substatus' => 2]],
                ],
              ],
            ];
            break;

          // Legacy parameters to support old filters.
          // Accepted or plausible.
          case '-3':
            $defs[] = [
              'bool' => [
                'should' => [
                  // Verified.
                  ['term' => ['identification.verification_status' => 'V']],
                  // Or plausible.
                  [
                    'bool' => [
                      'filter' => [
                        ['term' => ['identification.verification_status' => 'C']],
                        ['term' => ['identification.verification_substatus' => 3]],
                      ],
                    ],
                  ],
                ],
              ],
            ];
            break;

          // Not queried or rejected.
          case '!D':
            $defs[] = [
              'bool' => [
                'must_not' => [
                  ['term' => ['identification.verification_status' => 'R']],
                  ['terms' => ['identification.query.keyword' => ['Q', 'A']]],
                ],
              ],
            ];
            break;

          // Not rejected.
          case '!R':
            $defs[] = [
              'bool' => [
                'must_not' => [
                  ['term' => ['identification.verification_status' => 'R']],
                ],
              ],
            ];
            break;

          // Recorder certain.
          case 'C':
            $defs[] = [
              'bool' => [
                'filter' => [
                  ['term' => ['identification.recorder_certainty.keyword' => 'Certain']],
                ],
                'must_not' => [
                  ['term' => ['identification.verification_status' => 'R']],
                ],
              ],
            ];
            break;

          // Queried or not accepted.
          case 'DR':
            $defs[] = [
              'bool' => [
                'should' => [
                  ['term' => ['identification.verification_status' => 'R']],
                  ['match' => ['identification.query' => 'Q']],
                ],
              ],
            ];
            break;

          // Recorder thinks record identification is likely.
          case 'L':
            $defs[] = [
              'bool' => [
                'filter' => [
                  [
                    'terms' => [
                      'identification.recorder_certainty.keyword' => [
                        'Certain',
                        'Likely',
                      ],
                    ],
                  ],
                ],
                'must_not' => [
                  ['term' => ['identification.verification_status' => 'R']],
                ],
              ],
            ];
            break;

          default:
            // Nothing to do for 'all'.
        }
      }
      if (!empty($defs)) {
        $boolGroup = !empty($filter['op']) && $filter['op'] === 'not in' ? 'must_not' : 'filter';
        if (count($defs) === 1) {
          // Single filter can be simplified.
          $bool[$boolGroup][] = [array_keys($defs[0])[0] => array_values($defs[0])[0]];
        }
        else {
          // Join multiple filters with OR.
          $bool[$boolGroup][] = ['bool' => ['should' => $defs]];
        }
      }
    }
  }

  /**
   * Converts an Indicia filter id difficulty filter to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersIdentificationDifficulty(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, ['identification_difficulty']);
    if (!empty($filter) && !empty($filter['op'])) {
      if (in_array($filter['op'], ['>=', '<='])) {
        $test = $filter['op'] === '>=' ? 'gte' : 'lte';
        $bool['filter'][] = [
          'range' => [
            'identification.auto_checks.identification_difficulty' => [
              $test => $filter['value'],
            ],
          ],
        ];
      }
      else {
        $bool['filter'][] = ['term' => ['identification.auto_checks.identification_difficulty' => $filter['value']]];
      }
    }
  }

  /**
   * Converts an Indicia filter definition rule checks filter to an ES query.
   *
   * Handles both automatic checks and a user's custom verification rule flags.
   * Note that custom rule checks are not supported.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersRuleChecks(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, ['autochecks']);
    if (!empty($filter)) {
      if (in_array($filter['value'], ['P', 'F'])) {
        // Pass or Fail options are auto-checks from the Data Cleaner module.
        $bool['filter'][] = [
          'match' => [
            'identification.auto_checks.result' => $filter['value'] === 'P',
          ],
        ];
        if ($filter['value'] === 'P') {
          $bool['filter'][] = [
            'query_string' => ['query' => '_exists_:identification.auto_checks.verification_rule_types_applied'],
          ];
        }
      }
    }
  }

  /**
   * Converts an Indicia filter definition auto checks filter to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersAutoCheckRule(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, ['autocheck_rule']);
    if (!empty($filter)) {
      $value = str_replace('_', '', $filter['value']);
      $bool['filter'][] = [
        'term' => ['identification.auto_checks.output.rule_type' => $value],
      ];
    }

  }

  /**
   * Converts an Indicia filter definition website_list to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersWebsiteList(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, [
      'website_list',
      'website_id',
    ]);
    if (!empty($filter)) {
      $boolClause = !empty($filter['op']) && $filter['op'] === 'not in' ? 'must_not' : 'filter';
      $bool[$boolClause][] = [
        'terms' => ['metadata.website.id' => $this->safeExplodeCsvIntArray($filter['value'])],
      ];
    }
  }

  /**
   * Converts an Indicia filter definition survey_list to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersSurveyList(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, [
      'survey_list',
      'survey_id',
    ]);
    if (!empty($filter)) {
      $boolClause = !empty($filter['op']) && $filter['op'] === 'not in' ? 'must_not' : 'filter';
      $bool[$boolClause][] = [
        'terms' => ['metadata.survey.id' => $this->safeExplodeCsvIntArray($filter['value'])],
      ];
    }
  }

  /**
   * Converts an Indicia filter definition import_guid_list to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersImportGuidList(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, ['import_guid_list']);
    if (!empty($filter)) {
      $boolClause = !empty($filter['op']) && $filter['op'] === 'not in' ? 'must_not' : 'filter';
      $bool[$boolClause][] = [
        'terms' => [
          'metadata.import_guid' => explode(',', str_replace("'", '', $filter['value'])),
        ],
      ];
    }
  }

  /**
   * Converts an Indicia filter definition input_form_list to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersInputFormList(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, ['input_form_list']);
    if (!empty($filter)) {
      $boolClause = !empty($filter['op']) && $filter['op'] === 'not in' ? 'must_not' : 'filter';
      $bool[$boolClause][] = [
        'terms' => [
          'metadata.input_form' => explode(',', str_replace("'", '', $filter['value'])),
        ],
      ];
    }
  }

  /**
   * Converts an Indicia filter definition group_id to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersGroupId(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, ['group_id']);
    if (!empty($filter)) {
      $bool['filter'][] = [
        'terms' => ['metadata.group.id' => $this->safeExplodeCsvIntArray($filter['value'])],
      ];
    }
  }

  /**
   * Converts an Indicia filter definition scratchpad list to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersTaxaScratchpadList(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, ['taxa_scratchpad_list_id']);
    if (!empty($filter)) {
      // Convert the IDs to external keys, stored in ES as taxon_ids.
      $taxonData = $this->get("$this->warehouseUrl/index.php/services/report/requestReport", [
        'report' => 'library/taxa/external_keys_for_scratchpad.xml',
        'reportSource' => 'local',
        'sharing' => 'data_flow',
        'scratchpad_list_id' => $filter['value'],
      ]);
      $keys = [];
      foreach ($taxonData as $taxon) {
        $keys[] = $taxon['external_key'];
      }
      $bool['filter'][] = ['terms' => ['taxon.higher_taxon_ids' => $keys]];
    }
  }

  /**
   * Converts an Indicia filter definition has_photos filter to an ES query.
   *
   * @param array $definition
   *   Definition loaded for the Indicia filter.
   * @param array $bool
   *   Bool clauses that filters can be added to (e.g. $bool['filter']).
   */
  private function applyUserFiltersHasPhotos(array $definition, array &$bool) {
    $filter = $this->getDefinitionFilter($definition, ['has_photos']);
    if (!empty($filter)) {
      $boolClause = !empty($filter['op']) && $filter['op'] === 'not in' ? 'must_not' : 'filter';
      $bool[$boolClause][] = [
        'nested' => [
          'path' => 'occurrence.media',
          'query' => [
            'bool' => [
              'filter' => ['exists' => ['field' => 'occurrence.media']],
            ],
          ],
        ],
      ];
    }
  }

  /**
   * Applies the list of shared data flow websites to the filter.
   */
  private function applySharingAgreement(array &$bool) {
    $websites = $this->get("$this->warehouseUrl/index.php/services/report/requestReport", [
      'report' => 'library/websites/websites_list.xml',
      'reportSource' => 'local',
      'sharing' => 'data_flow',
    ]);
    $websiteIds = [$this->websiteID];
    foreach ($websites as $website) {
      $websiteIds[] = (int) $website['id'];
    }
    sort($websiteIds);
    $bool['filter'][] = [
      'terms' => ['metadata.website.id' => array_unique($websiteIds)],
    ];
  }

  /**
   * Retrieve database records from the Indicia warehouse.
   *
   * @param array $options
   *   Provide the following entries in the options array:
   *   * table - the singular name of the database table to read from.
   *   * id - optional ID of record to load.
   *   * params - array of field/value pairs to provide as a filter to the data
   *     services request. E.g. specify a record ID to load.
   *
   * @return array
   *   Array of records, with each record being defined by an associative array
   *   of field values.
   */
  public function getData(array $options): array {
    if (!isset($options['table'])) {
      throw new Exception('Please supply the singular name of the table you want to read data from in the options array');
    }
    $request = "$this->warehouseUrl/index.php/services/data/$options[table]";
    if (isset($options['id'])) {
      $request .= "/$options[id]";
    }
    if (!isset($options['params'])) {
      $options['params'] = [];
    }
    return $this->get($request, $options['params']);
  }

  /**
   * A generic internal method for sending a request to the web-services.
   *
   * @param string $request
   *   The URL to request data from.
   * @param array $params
   *   Parameters to add to the URL as a query string.
   *
   * @return array
   *   List of records returned by the request.
   */
  private function get($request, $params): array {
    $params = array_merge([
      'mode' => 'json',
      'auth_token' => $this->readAuth['auth_token'],
      'nonce' => $this->readAuth['nonce'],
    ], $params);
    $request .= '?' . http_build_query($params);
    return json_decode($this->http_post($request), TRUE);
  }

  /**
   * Internal method to retrieve auth tokens required for the warehouse.
   *
   * @return array
   *   Read tokens array.
   */
  private function getReadAuth(): array {
    $postargs = "website_id=" . $this->websiteID;
    $nonce = $this->http_post($this->warehouseUrl . '/index.php/services/security/get_read_nonce', $postargs);
    return [
      'auth_token' => sha1("$nonce:$this->websitePassword"),
      'nonce' => $nonce,
    ];
  }

  /**
   * Internal method which posts data to a specified URL.
   *
   * @param string $url
   *   Web services URL to post data to.
   * @param string $postargs
   *   Query string to include in the post.
   *
   * @return bool|string
   *   Response from the cUrl call to the warehouse.
   */
  private function http_post($url, $postargs = NULL): bool|string {
    $session = curl_init();
    // Set the POST options.
    curl_setopt($session, CURLOPT_URL, $url);
    if ($postargs !== NULL) {
      curl_setopt($session, CURLOPT_POST, TRUE);
      curl_setopt($session, CURLOPT_POSTFIELDS, $postargs);
    }
    curl_setopt($session, CURLOPT_HEADER, FALSE);
    curl_setopt($session, CURLOPT_RETURNTRANSFER, TRUE);
    // Do the POST.
    $response = curl_exec($session);
    $httpCode = curl_getinfo($session, CURLINFO_HTTP_CODE);
    // Check for an error, or check if the http response was not OK.
    if (curl_errno($session) || $httpCode !== 200) {
      if (curl_errno($session)) {
        throw new Exception(curl_errno($session) . ' - ' . curl_error($session));
      }
      else {
        throw new Exception($httpCode . ' - ' . $response);
      }
    }
    curl_close($session);
    return $response;
  }

  /**
   * Return the CSV file to output raw data into.
   *
   * @return string
   *   File name.
   */
  private function getOutputCsvFileName(array $fileMetadata): string {
    if ($this->conf['outputType'] === 'csv' && count($this->dataFiles) === 1) {
      return $this->conf['outputFile'];
    }
    return $fileMetadata['filename'];
  }

  /**
   * If the file type is DwcA, build the zip file.
   *
   * Adds the occurrences CSV file and the optional XML files.
   */
  private function updateDwcaFile() {
    $zip = new ZipArchive();
    $zip->open($this->conf['outputFile'], ZipArchive::CREATE);
    echo "Zip archive file opened\n";
    echo $this->conf['outputFile'] . "\n";
    foreach ($this->dataFiles as $fileMetadata) {
      $zip->addFile($this->getOutputCsvFileName($fileMetadata));
    }
    // If the EML and metadata files are specified then add them.
    if (!empty($this->conf['xmlFilesInDir'])) {
      $zip->addFile($this->conf['xmlFilesInDir'] . DIRECTORY_SEPARATOR . 'eml.xml', 'eml.xml');
      $zip->addFile($this->conf['xmlFilesInDir'] . DIRECTORY_SEPARATOR . 'meta.xml', 'meta.xml');
    }
    $zip->close();
    foreach ($this->dataFiles as $fileMetadata) {
      // Don't need the CSV file - has to be done after zip close.
      unlink($this->getOutputCsvFileName($fileMetadata));
    }
  }

  /**
   * Return the array to represent an occurrence document as DwcA CSV.
   *
   * @param array $source
   *   ES occurrence document source.
   *
   * @return array
   *   CSV data.
   */
  private function getOccurrenceRowData(array $source, array $fileMetadata): array {
    $points = explode(',', $source['location']['point']);
    $sensitiveOrNotPoint = (isset($source['metadata']['sensitive']) && $source['metadata']['sensitive'] === 'true') ||
      (isset($source['location']['input_sref_system']) && !preg_match('/^\d+$/', $source['location']['input_sref_system']));
    $useGridRefsIfPossible = in_array('useGridRefsIfPossible', $this->conf['options']);
    $isDnaDerived = ($source['occurrence']['dna_derived'] ?? 'false') === 'true';
    $availableData = [
      'occurrenceID' => $this->conf['occurrenceIdPrefix'] . $source['id'],
      'id' => $this->conf['occurrenceIdPrefix'] . $source['id'],
      'otherCatalogNumbers' => empty($source['occurrence']['source_system_key']) ? '' : $source['occurrence']['source_system_key'],
      'eventID' => $this->conf['eventIdPrefix'] . $source['event']['event_id'],
      'parentEventID' => isset($source['event']['parent_event_id']) ? $this->conf['eventIdPrefix'] . $source['event']['parent_event_id'] : NULL,
      // If an extension, we only support occurrences being an extension of
      // events, so the coreid will always point to an event.
      'coreid' => $this->conf['eventIdPrefix'] . $source['event']['event_id'],
      'scientificName' => isset($source['taxon']['accepted_name'])
        ? ($source['taxon']['accepted_name'] . (empty($source['taxon']['accepted_name_authorship']) ? '' : ' ' . $source['taxon']['accepted_name_authorship']))
        : $source['taxon']['taxon_name'],
      'taxonID' => $source['taxon']['accepted_taxon_id'] ?? $source['taxon']['taxon_id'],
      'lifeStage' => empty($source['occurrence']['life_stage']) ? '' : $source['occurrence']['life_stage'],
      'sex' => empty($source['occurrence']['sex']) ? '' : $source['occurrence']['sex'],
      'individualCount' => empty($source['occurrence']['organism_quantity']) ? '' : $source['occurrence']['organism_quantity'],
      'vernacularName' => empty($source['taxon']['vernacular_name']) ? '' : $source['taxon']['vernacular_name'],
      'eventDate' => $this->getDate($source),
      'year' => $source['event']['year'] ?? '',
      'month' => $source['event']['month'] ?? '',
      'recordedBy' => empty($source['event']['recorded_by']) ? '' : $source['event']['recorded_by'],
      // Tolerate DwC/US English or UK English.
      'licence' => empty($source['metadata']['licence_code']) ? $this->conf['defaultLicenceCode'] : $source['metadata']['licence_code'],
      'license' => empty($source['metadata']['licence_code']) ? $this->conf['defaultLicenceCode'] : $source['metadata']['licence_code'],
      'rightsHolder' => $this->conf['rightsHolder'],
      'coordinateUncertaintyInMeters' => empty($source['location']['coordinate_uncertainty_in_meters']) ? '' : $source['location']['coordinate_uncertainty_in_meters'],
      'gridReference' => $useGridRefsIfPossible && $sensitiveOrNotPoint ? $source['location']['output_sref'] : '',
      'decimalLatitude' => $useGridRefsIfPossible && $sensitiveOrNotPoint ? '' : $points[0],
      'decimalLongitude' => $useGridRefsIfPossible && $sensitiveOrNotPoint ? '' : $points[1],
      'geodeticDatum' => 'WGS84',
      'datasetName' => $this->conf['datasetName'],
      'datasetID' => $this->getDatasetId($source),
      'collectionCode' => $this->getCollectionCode($source),
      'locality' => empty($source['location']['verbatim_locality']) ? '' : $source['location']['verbatim_locality'],
      // DNA may have a different basis of record value.
      'basisOfRecord' => $isDnaDerived && isset($this->conf['basisOfRecordDna']) ? $this->conf['basisOfRecordDna'] : $this->conf['basisOfRecord'],
      'identificationVerificationStatus' => $this->getIdentificationVerificationStatus($source),
      'identifiedBy' => empty($source['identification']['identified_by']) ? '' : $source['identification']['identified_by'],
      'occurrenceStatus' => $source['occurrence']['zero_abundance'] === 'true' ? 'absent' : 'present',
      'habitat' => empty($source['event']['habitat']) ? '' : $source['event']['habitat'],
      'eventRemarks' => $this->formatRemarks($source['event']['event_remarks'] ?? ''),
      'occurrenceRemarks' => $this->formatRemarks($source['occurrence']['occurrence_remarks'] ?? ''),
      'samplingProtocol' => $source['event']['sampling_protocol'] ?? '',
      'associatedMedia' => $this->getAssociatedMedia('occurrence', $source),
    ];
    return $this->getRow('occurrence', $fileMetadata['columns'], $source, $availableData);
  }

  /**
   * Return the array to represent an event document as DwcA CSV.
   *
   * @param array $source
   *   ES event document source.
   *
   * @return array
   *   CSV data.
   */
  private function getEventRowData(array $source, array $fileMetadata): array {
    $points = explode(',', $source['location']['point']);
    $sensitiveOrNotPoint = (isset($source['metadata']['sensitive']) && $source['metadata']['sensitive'] === 'true') ||
      (isset($source['location']['input_sref_system']) && !preg_match('/^\d+$/', $source['location']['input_sref_system']));
    $useGridRefsIfPossible = in_array('useGridRefsIfPossible', $this->conf['options']);
    $availableData = [
      'eventID' => $this->conf['eventIdPrefix'] . $source['id'],
      'id' => $this->conf['eventIdPrefix'] . $source['id'],
      'parentEventID' => isset($source['event']['parent_event_id']) ? $this->conf['eventIdPrefix'] . $source['event']['parent_event_id'] : NULL,
      'eventDate' => $this->getDate($source),
      'year' => $source['event']['year'] ?? '',
      'month' => $source['event']['month'] ?? '',
      'coordinateUncertaintyInMeters' => empty($source['location']['coordinate_uncertainty_in_meters']) ? '' : $source['location']['coordinate_uncertainty_in_meters'],
      'gridReference' => $useGridRefsIfPossible && $sensitiveOrNotPoint ? $source['location']['output_sref'] : '',
      'decimalLatitude' => $useGridRefsIfPossible && $sensitiveOrNotPoint ? '' : $points[0],
      'decimalLongitude' => $useGridRefsIfPossible && $sensitiveOrNotPoint ? '' : $points[1],
      'geodeticDatum' => 'WGS84',
      'habitat' => empty($source['event']['habitat']) ? '' : $source['event']['habitat'],
      'eventRemarks' => $this->formatRemarks($source['event']['event_remarks'] ?? ''),
      'samplingProtocol' => $source['event']['sampling_protocol'] ?? '',
      'associatedMedia' => $this->getAssociatedMedia('event', $source),
    ];
    return $this->getRow('event', $fileMetadata['columns'], $source, $availableData);
  }

  /**
   * Return the array to represent a DNA document as DwcA CSV.
   *
   * @param array $source
   *   ES occurrence document source.
   *
   * @return array
   *   CSV data.
   */
  private function getDnaDerivedDataRowData(array $source, array $fileMetadata): array {
    $availableData = [
      'occurrenceID' => $this->conf['occurrenceIdPrefix'] . $source['id'],
      // If an extension, we only support DNA occurrences being an extension of
      // events, so the coreid will always point to an event.
      'coreid' => $this->conf['eventIdPrefix'] . $source['event']['event_id'],
      'eventID' => $this->conf['eventIdPrefix'] . $source['event']['event_id'],
      'dna_sequence' => $source['dna_derived_data']['dna_sequence'] ?? '',
      'associatedSequences' => implode(';', $source['dna_derived_data']['associated_sequences'] ?? []),
      'target_gene' => $source['dna_derived_data']['target_gene'] ?? '',
      'pcr_primer_reference' => $source['dna_derived_data']['pcr_primer_reference'] ?? '',
      'env_medium' => $source['dna_derived_data']['env_medium'] ?? '',
      'env_broad_scale' => $source['dna_derived_data']['env_broad_scale'] ?? '',
      'otu_db' => $source['dna_derived_data']['otu_db'] ?? '',
      'otu_seq_comp_appr' => $source['dna_derived_data']['otu_seq_comp_appr'] ?? '',
      'otu_class_appr' => $source['dna_derived_data']['otu_class_appr'] ?? '',
      'env_local_scale' => $source['dna_derived_data']['env_local_scale'] ?? '',
      'target_subfragment' => $source['dna_derived_data']['target_subfragment'] ?? '',
      'pcr_primer_name_forward' => $source['dna_derived_data']['pcr_primer_name_forward'] ?? '',
      'pcr_primer_forward' => $source['dna_derived_data']['pcr_primer_forward'] ?? '',
      'pcr_primer_name_reverse' => $source['dna_derived_data']['pcr_primer_name_reverse'] ?? '',
      'pcr_primer_reverse' => $source['dna_derived_data']['pcr_primer_reverse'] ?? '',
    ];
    return $this->getRow('dna_derived_data', $fileMetadata['columns'], $source, $availableData);
  }

  /**
   * Build an output row's data array.
   *
   * @param string $class
   *   Type of data to output, occurrence, event or dna_derived_data.
   * @param array $columns
   *   List of DwC terms to include in the output, in the order they should appear.
   * @param array $source
   *   Document source from Elasticsearch.
   * @param array $availableData
   *   Data key/value pairs that can be used in the row.
   *
   * @return array
   *   Output row data array.
   */
  function getRow($class, array $columns, array $source, array $availableData): array {
    $row = [];
    // Fetch field customisations.
    $customFields = $this->conf['customFields'][$class] ?? [];
    foreach ($columns as $dwcTerm) {
      if (isset($customFields[$dwcTerm])) {
        $fn = 'customGet' . $customFields[$dwcTerm][0];
        $params = $customFields[$dwcTerm][1];
        if (!method_exists($this, $fn)) {
          throw new Exception("Invalid customField function name $fn");
        }
        $row[] = $this->$fn($source, $params);
      }
      else {
        $row[] = $availableData[$dwcTerm] ?? '';
      }
    }
    return array_values($row);
  }

  /**
   * Custom field function that obtains a custom attribute value.
   *
   * @param array $source
   *   Occurrence or event document.
   * @param array $params
   *   Function parameters. The first parameter should be 'occurrence' or
   *   'event' depending on the type of attribute. The second should be the ID
   *   of the attribute value to fetch.
   *
   * @return string|null
   *   Attribute value, or NULL if not specified for this record.
   */
  private function customGetAttributeValue(array $source, array $params): mixed {
    if (!in_array($params[0], ['occurrence', 'event'])) {
      throw new Exception('Incorrect customField structure in configuration file.');
    }
    foreach ($source[$params[0]]['attributes'] ?? [] as $attr) {
      if (!preg_match('/^\d+$/', $params[1])) {
        throw new Exception('Incorrect customField structure in configuration file.');
      }
      if ($attr['id'] == $params[1]) {
        return $attr['value'];
      }
    }
    // Default.
    return NULL;
  }

  /**
   * Retrieve an object containing the values of one or more custom attributes.
   *
   * @param array $source
   *   Occurrence or event document.
   * @param array $params
   *   Function parameters. The first parameter should be 'occurrence' or
   *   'event' depending on the type of attribute. The second should be an
   *   associative array where the keys are the property names to include in
   *   the returned object and the values are the IDs of the associated
   *   attribute values.
   *
   * @return string
   *   Associative array of found values, encoded as a JSON string.
   */
  private function customGetAttributesObject(array $source, array $params): string {
    $obj = [];
    foreach ($params[1] as $caption => $attrId) {
      $value = $this->customGetAttributeValue($source, [$params[0], $attrId]);
      if ($value !== NULL) {
        $obj[$caption] = $value;
      }
    }
    return json_encode($obj);
  }

  /**
   * If IPT option enabled, then new lines in remarks need to be converted to <br>.
   *
   * @param string $remarks
   *   Remarks to format.
   *
   * @return string
   *   Formatted remarks.
   */
  private function formatRemarks($remarks): string {
    if (in_array('ipt', $this->conf['options'])) {
      return str_replace(["\r\n", "\r", "\n"], '<br>', trim($remarks));
    }
    else {
      return trim($remarks);
    }
  }

  /**
   * Format date info from ES document as DwC event date.
   *
   * @param array $source
   *   ES Document source.
   *
   * @return string
   *   Date string.
   *
   * @todo Following is simplistic, doesn't handle YYYY, YYYY-MM, YYYY/YYYY or YYYY-MM/YYYY-MM formats.
   */
  private function getDate(array $source): string {
    $dateStart = $source['event']['date_start'] ?? '';
    $dateEnd = $source['event']['date_end'] ?? '';
    return $dateStart . ($dateStart === $dateEnd ? '' : '/' . $source['event']['date_end']);
  }

  /**
   * Extract dataset ID from an ES document.
   *
   * @param array $source
   *   ES Document source.
   *
   * @return string
   *   Dataset ID or empty string if not present.
   */
  private function getDatasetId(array $source): string {
    if (!empty($this->conf['datasetIdSampleAttrId']) && !empty($source['event']['attributes'])) {
      foreach ($source['event']['attributes'] as $attr) {
        if ($attr['id'] == $this->conf['datasetIdSampleAttrId']) {
          return $attr['value'];
        }
      }
    }
    return '';
  }

  /**
   * Format website and survey title as CollectionCode.
   *
   * @param array $source
   *   ES Document source.
   *
   * @return string
   *   CollectionCode string.
   */
  private function getCollectionCode(array $source): string {
    $website = $source['metadata']['website']['title'];
    $survey = $source['metadata']['survey']['title'];
    $uniquePartOfSurveyName = ucfirst(trim(preg_replace('/^' . $website . '/', '', $survey)));
    return "$website | $uniquePartOfSurveyName";
  }

  /**
   * Format record status as identificationVerificationStatus.
   *
   * @param array $source
   *   ES Document source.
   *
   * @return string
   *   IdentificationVerificationStatus string.
   */
  private function getIdentificationVerificationStatus(array $source): string {
    $status = $source['identification']['verification_status'] . $source['identification']['verification_substatus'];
    switch ($status) {
      case 'V0':
        return 'Accepted';

      case 'V1':
        return 'Accepted - correct';

      case 'V2':
        return 'Accepted - considered correct';

      case 'C0':
        return 'Unconfirmed - not reviewed';

      case 'C3':
        return 'Unconfirmed - plausible';

      default:
        return '';
    }
  }

  /**
   * Get list of media files.
   *
   * @param string $type
   *   Specify "event" or "occurrence".
   * @param array $source
   *   ES Document source.
   *
   * @return string
   *   String containing | separated list of URLs to media.
   */
  private function getAssociatedMedia($type, array $source): string {
    $list = [];
    foreach ($source[$type]['media'] ?? [] as $media) {
      $list[] = $this->warehouseUrl . '/upload/' . $media['path'];
    }
    return implode('|', $list);
  }

  /**
   * Explode a CSV integer list into an array, with format check.
   *
   * @param string $csv
   *   Integers as CSV string.
   *
   * @return string[]
   *   Array of integers.
   */
  private function safeExplodeCsvIntArray($csv): array {
    if (!preg_match('/^\d+(,\d+)*$/', str_replace(' ', '', $csv))) {
      throw new Exception('Invalid CSV integer array: ' . $csv);
    }
    return explode(',', $csv);
  }

}

// Startup.

if (count($argv) !== 2) {
  die('Supply a single argument which contains the name of the config file to load.');
}
$configFile = $argv[1];
$helper = new BuildDwcHelper($configFile);
$helper->buildFiles($configFile);
