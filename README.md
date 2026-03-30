# Indicia Darwin Core Exporter

A small PHP script for extracting data in an Indicia Warehouse Elasticsearch instance into a
Darwin Core archive. Can produce Comma Separated Values (*.csv) files as well.

To run the exporter, it needs to be placed on a machine with visibility of the Elasticsearch server
and with PHP 8.1 or higher installed. You will also need a config file per export, plus a warehouse
connection configuration file as described below before running the script.

Run the script from the command line by invoking PHP.exe, providing the location of the script PHP
file (dwc-generate.php) and a configuration file as parameters.

```bash
$ c:\PHP\php.exe c:\dwc-generate\dwc-generate.php "config\my export.json"
```

This can be saved as a batch file or shell script and invoked using Windows Task Scheduler or cron.

# Config file

The config file is in JSON format and the file is passed as a parameter to the dwc-generate PHP
script. This file contains the following options:

* batchSize - optional, number of Elasticsearch documents fetched per scroll request. Defaults to
  1000.
* basisOfRecord - optional, defaults to "HumanObservation". Default basisOfRecord exported for
  occurrence data other than DNA-derived.
* basisOfRecordDna - optional, defaults to "MaterialSample". Default basisOfRecord exported for
  DNA-derived occurrence data.
* customFields - an optional configuration object where the top-level property names are either
  "occurrence" or "event" allowing custom fields to be attached to either event or occurrenc data.
  Each of these properties contains an array of DwC term names with configuration for a function
  which will replace the output value for that term with a custom value (for example a custom
  attribute value). The configuration is an array where the first value is a supported custom
  function name and the second array entry is the parameter to pass to the function. Currently 2
  custom functions are supported:
  * AttributeValue - fetch a single attribute value for either the event or occurrence attributes
    associated with the record. The parameter required is the attribute ID to fetch the value for.
  * AttributesObject - fetch an object with named properties, each containing a custom attribute
    value. The parameter is an object where the property names will be copied into the returned
    object and the values are attribute IDs to fetch the values for.
  An example of the correct structure is:
  ```json
    "customFields": {
      "occurrence": {
        "samplingProtocol": ["AttributeValue", ["occurrence", 123]],
        "dynamicProperties": ["AttributesObject", ["occurrence", {"Wingspan": 456, "Prey taken": 789}]]
      }
    }
  ```
* datasetIdSampleAttrId - ID of the sample attribute which holds the datasetID value.
* datasetName - Darwin Core datasetName to specify if there is a datasetName column in the
  meta.xml file.
* defaultLicenceCode - optional, default licence to apply if not specified at the record level.
  e.g. "CC-BY".
* elasticsearchHost - the web address or IP address of the server, including the port number. E.g.
  "x.x.x.x:9200" where x.x.x.x is the server IP address.
* eventIdPrefix - optional, prefix to use when constructing the occurrenceID, e.g. "brcevt1|".
* eventIndex - if the meta.xml metafile specifies that event data should be output, then provide
  the name of the Elasticsearch alias or index to query. This index should contain event data for
  each document rather than occurrences and should include events that contain zero occurrences.
* eventQuery - if event data is being output, then define a query here which will be used to filter
  the data fetched from the event index. If not specified, then the query configuration will be
  used.
* filterId - optional, but either query or filterId must be specified and both are applied if both
  are present. ID of the filter record on the warehouse which will be used to dynamically generate
  the query. The list of websites available for data flow (according to the website registration
  configured in warehouse.json) will be automatically applied to the filter.
* fullPrecision - defaults to false. Set to true to export full precision data rather than blurred
  for any sensitive or private data.
* higherGeographyID - this is a shortcut to specifying a nested term filter on a higher geography
  id which limits the output to records which intersect the provided location ID. The location must
  be indexed by the spatial_index_builder module.
* index - name of the Elasticsearch alias or index to query.
* occurrenceIdPrefix - optional, prefix to use when constructing the occurrenceID, e.g. "brc1|".
* options - array of options to extend data with.
  * useGridRefsIfPossible - for NBN Atlas export compatibility, switch to using the gridReference
    field instead of decimalLatitude and decimalLongitude where appropriate.
  * ipt - set this flag to true to enable IPT compatibility, which means that new lines inside
    comment fields will be replaced by <br>.
* outputFile - optional output file name, relative or absolute file path. Use when the output type
  is dwca (Darwin Core Archive), or when the output type is csv and only a single output file is
  specified in the meta.xml file. Existing CSV files will be overwritten and existing Darwin Core
  Archive zip files will have the occurrences contents updated. If not specified then uses the
  config file name to default to `exports/<config file name>.<ext>`.

  Note that when 2 or more output files are specified in meta.xml for a CSV export, then the
  outputFile setting is ignored and the filenames of the individual CSV files must be specified in
  the `<files><location>` element within the `<core>` or `<extension>` element that describes the
  file.
* outputType - specify either dwca (Darwin Core Archive) or csv.
* query - optional Elasticsearch query to filter the data to the dataset. Either query or filterId
  must be specified. For example:
  ```json
  {
    ...
    "query": {
      "bool": {
        "filter": {
          "term": {"metadata.website.id": 2}
        }
      }
    }
    ...
  }
  ```
* repeatExport - optional. Allows a single configuration file to define a set of several similar
  exports, for example you might want to create a series of exports which are identical but divide
  the data by country. Provide an array, containing an object per export file with properties that
  will be merged with the top-level configuration provided in the configuration. E.g. you can
  specify `datasetName` in the `repeatExport` property's objects to define a different dataset name
  per file. You can also use the `surveyId` and `higherGeographyId` filter shortcut options to
  easily divide the files on either survey or location. An example of the `repeatExport`
  configuration is provided in the file `config/export-example-occurrence-bulk.json`.
* rightsHolder - Darwin Core rightsHolder to specify if there is a rightsHolder column in the
  meta.xml file.
* scrollKeepAlive - optional, Elasticsearch scroll context keepalive duration used between
  requests. Defaults to `2m`. Increase if processing each batch can take longer on your
  infrastructure.
* scrollRetryCount - optional, number of retries for each failed Elasticsearch scroll request.
  Defaults to 1.
* scrollRetryDelayMs - optional, delay between scroll retries in milliseconds. Defaults to 500.
* surveyId - this is a shortcut to specifying a term filter on the survey ID (`metadata.survey.id`)
  which limits the output to a single survey dataset.
* xmlFilesInDir - if creating a Darwin Core Archive file, then the eml.xml and meta.xml files need
  to be in a sub-directory specified by this setting and they will be added to the DwC-A Zip
  archive file. If not specified but a folder exists with the same filename as the json config file
  in a metadata subfolder, then this will be used. E.g. if the config file is called
  `aculeates.json` then the expected location would be `exports/aculeates`. If outputting a CSV
  file the eml.xml file is not required, but you should still provide meta.xml in order to dictate
  whether you are exporting Event or Occurrence data and which columns to include.

# Metafile

Additionally you must provide a file called meta.xml which conforms to the Darwin Core metafile
format (https://dwc.tdwg.org/text/) which is in a directory referred to by the xmlFilesInDir config
setting. The meta.xml file describes the output file(s) and the columns you want to include in each
file and is used to describe both Darwin Core Archive and CSV outputs. Options for data files to
include in the Darwin Core Archive or to output as CSV files are limited to the following:

* Core file contains occurrence data with no extension data (see
  metadata/export-example-occurrence/meta.xml).
* Core file contains occurrence data with no extension data (see
  metadata/export-example-event/meta.xml).
* Core file contains event data with occurrence data in an extension (see
  metadata/export-example-event-occurrence/meta.xml).
* Core file contains event data with occurrence data and DNA derived data in an extension (see
  metadata/export-example-event-occurrence-dna/meta.xml).

For event datasets, the following field terms are supported:
* http://rs.tdwg.org/dwc/terms/associatedMedia
* http://rs.tdwg.org/dwc/terms/coordinateUncertaintyInMeters
* http://rs.tdwg.org/dwc/terms/decimalLatitude
* http://rs.tdwg.org/dwc/terms/decimalLongitude
* http://rs.tdwg.org/dwc/terms/eventDate
* http://rs.tdwg.org/dwc/terms/eventID
* http://rs.tdwg.org/dwc/terms/eventRemarks
* http://rs.tdwg.org/dwc/terms/geodeticDatum
* http://data.nbn.org/nbn/terms/gridReference
* http://rs.tdwg.org/dwc/terms/habitat
* http://rs.tdwg.org/dwc/terms/locality
* http://rs.tdwg.org/dwc/terms/month
* http://rs.tdwg.org/dwc/terms/parentEventID
* http://rs.tdwg.org/dwc/terms/samplingProtocol
* http://rs.tdwg.org/dwc/terms/year

For occurrence datasets, the following field terms are supported:
* http://rs.tdwg.org/dwc/terms/associatedMedia
* http://rs.tdwg.org/dwc/terms/basisOfRecord
* http://rs.tdwg.org/dwc/terms/coordinateUncertaintyInMeters
* http://rs.tdwg.org/dwc/terms/collectionCode
* http://rs.tdwg.org/dwc/terms/datasetID
* http://rs.tdwg.org/dwc/terms/datasetName
* http://rs.tdwg.org/dwc/terms/decimalLatitude
* http://rs.tdwg.org/dwc/terms/decimalLongitude
* http://rs.tdwg.org/dwc/terms/dynamicProperties - must be configured using the "customFields" option in the config file.
* http://rs.tdwg.org/dwc/terms/eventDate
* http://rs.tdwg.org/dwc/terms/eventID
* http://rs.tdwg.org/dwc/terms/eventRemarks
* http://rs.tdwg.org/dwc/terms/geodeticDatum
* http://data.nbn.org/nbn/terms/gridReference
* http://rs.tdwg.org/dwc/terms/habitat
* http://rs.tdwg.org/dwc/terms/identifiedBy
* http://rs.tdwg.org/dwc/terms/identificationVerificationStatus
* http://rs.tdwg.org/dwc/terms/individualCount
* http://purl.org/dc/terms/license
* http://rs.tdwg.org/dwc/terms/lifeStage
* http://rs.tdwg.org/dwc/terms/locality
* http://rs.tdwg.org/dwc/terms/month
* http://rs.tdwg.org/dwc/terms/occurrenceID
* http://rs.tdwg.org/dwc/terms/occurrenceRemarks
* http://rs.tdwg.org/dwc/terms/occurrenceStatus
* http://rs.tdwg.org/dwc/terms/otherCatalogNumbers
* http://rs.tdwg.org/dwc/terms/parentEventID
* http://rs.tdwg.org/dwc/terms/recordedBy
* http://purl.org/dc/terms/rightsHolder
* http://rs.tdwg.org/dwc/terms/samplingProtocol
* http://rs.tdwg.org/dwc/terms/scientificName
* http://rs.tdwg.org/dwc/terms/sex
* http://rs.tdwg.org/dwc/terms/taxonID
* http://rs.tdwg.org/dwc/terms/year
* http://rs.tdwg.org/dwc/terms/vernacularName

For DNA derived datasets, the following field terms are supported:
* http://rs.tdwg.org/dwc/terms/eventID
* http://rs.tdwg.org/dwc/terms/occurrenceID
* http://rs.gbif.org/terms/dna_sequence
* http://rs.tdwg.org/dwc/terms/associatedSequences
* https://w3id.org/mixs/0000044 (target_gene)
* http://rs.gbif.org/terms/pcr_primer_reference
* https://w3id.org/mixs/0000014 (env_medium)
* https://w3id.org/mixs/0000012 (env_broad_scale)
* https://w3id.org/mixs/0000087 (otu_db)
* https://w3id.org/mixs/0000086 (otu_seq_comp_appr)
* https://w3id.org/mixs/0000085 (otu_class_appr)
* https://w3id.org/mixs/0000013 (env_local_scale)
* https://w3id.org/mixs/0000045 (target_subfragment)
* http://rs.gbif.org/terms/pcr_primer_name_forward
* http://rs.gbif.org/terms/pcr_primer_forward
* http://rs.gbif.org/terms/pcr_primer_name_reverse
* http://rs.gbif.org/terms/pcr_primer_reverse

When your meta.xml file contains a core event file and an extension occurrence file, you should add
an element called `<id>` to the the list of fields for the event, plus `<coreid>` to the list of
fields for the occurrence. Both should be at the start of the list of columns with index "0" but it
is acceptable to also define the eventID field column as index 0 immediately after the coreid or id
column in the list, so that a single column is output which serves both purposes. See
https://dwc.tdwg.org/text/#212-elements. If the meta.xml file contains a core event file, an
occurrence file and a DNA derived data file then also add an element called `<coreid>` to the start
of the DNA derived data file columns list at index 0. See the examples folder.

# Warehouse connection config file

In order to configure the connection to the warehouse, create a file `config/warehouse.json` and
paste the following into it, replacing values in `<>` with the appropriate value for your system.
The warehouse_url setting just needs domain plus path to the folder containing the warehouse; it
does not need to include `/index.php` or anything after it.

```json
{
  "website_id": <webiste id>,
  "website_password": "<website password>",
  "warehouse_url": "<warehouse root url>",
  "master_checklist_id": <taxon list ID of main list>
}
```
