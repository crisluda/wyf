<?php
/*
 * Copyright (c) 2011 James Ekow Abaka Ainooson
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to
 * the following conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
 * OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 *
 */

/**
 * A special controller class for generating reports. The report controller
 * class allows reports to be generated in different formats. The programmer only
 * has to specify the details in the Report class format and the report
 * controller would handle all the issues which have to do with reporting
 * formats. The report generator also provides report configuration forms,
 * filter fields and nested table rendering for reporting purposes.
 *
 * @ingroup Controllers
 * @author ekowabaka
 */
abstract class ReportController extends Controller
{
    /**
     * This variable is set whenever a table has already been rendered. This
     * property is used during nested table rendering.
     * @var boolean
     */
    protected $tableRendered;

    /**
     * An array which stores the widths of the columns in a nested table
     * rendering.
     * @var array
     */
    protected $widths;
    public $referencedFields;
    protected $filters;
    protected $numFilters = 0;
    protected $reportData = array();
    protected $reportDataIndex = 0;
    protected $drawTotals = true;
    protected $dataParams;

    public function __construct()
    {
        $this->_showInMenu = true;
    }

    /**
     * Returns an instance of the report class. The instance returned by this
     * method depends on the report options selected in the forms. The form for
     * these options are generated by the ReportController::initializeForm()
     * method.
     * @return Report
     */
    public function getReport()
    {
        $report = new Report($_REQUEST['report_format']);
        return $report;
    }

    /**
     * Draws a report table. This method could be overriden in subclasses to
     * present another means of presenting data. The method returns the total
     * values of the table in an array form based on the data parameters.
     *
     * @param array $data The data to be displayed
     * @param array $params Special parameters attached to the parameters
     * @param array $dataParams More parameters
     * @param mixed $totalTable The object to use as the instance of the totals table
     * @param string $heading A special heading for the table if it is a nested table
     * @return array
     */
    protected function drawTable($report, &$table)
    {
        $tableCopy = $table;
        if(is_array($table['params']["ignored_fields"]))
        {
            foreach($table['params']["ignored_fields"] as $ignored)
            {
                unset($tableCopy["headers"][$ignored]);
                unset($tableCopy["params"]["type"][$ignored]);
                unset($tableCopy["params"]["total"][$ignored]);
                unset($this->widths[$ignored]);
            }

            foreach($table['data'] as $key => $row)
            {
                foreach($table['params']["ignored_fields"] as $ignored)
                {
                    unset($tableCopy['data'][$key][$ignored]);
                }
            }
        }
        
        $tableContent = new TableContent($tableCopy["headers"], $tableCopy['data']);
        
        $tableCopy['params']['widths'] = $this->widths;
        
        $tableContent->setDataParams($tableCopy['params']);
        $tableContent->setAutoTotals($table['totals']);
        
        $report->add($tableContent);
        $total = $tableContent->getTotals();
        
        return $total;
    }

    /**
     * Draws a heading for a nested table operation. This method could be
     * overridden by custom reports to provide a different means of presenting
     * table headings.
     * @param <type> $headingValue
     * @param <type> $params
     */
    protected function drawHeading($report, $headingValue)
    {
        $heading = new TextContent($headingValue, 'heading');
        $report->add($heading);
    }
    /**
     * Take an existing table and digest it into a summary table.
     * @param unknown_type $params
     */
    protected function generateSummaryTable($report, $params)
    {
    	$newFields = array($params["grouping_fields"][0]);
    	$newHeaders = array($params["headers"][array_search($params["grouping_fields"][0],$params["fields"])]);
    	$indices = array(array_search($params["grouping_fields"][0],$params["fields"]));
    	$newParams = array("total"=>array(false), "type"=>array("string"));
    	foreach($params["data_params"]['total'] as $index => $value)
    	{
            if($value === true)
            {
                $tempField = $params["fields"][$index];
                $newFields[] = $tempField;
                $newHeaders[] = $params["headers"][array_search($tempField,$params["fields"])];
                $indices[] = array_search($tempField,$params["fields"]);
                $newParams["total"][] = true;
                $newParams["type"][] = $params["data_params"]["type"][$index];
            }
    	}

    	$filteredData = array();
    	
    	foreach($this->reportData as $data)
    	{
            $row = array();
            foreach($indices as $index)
            {
                $row[] = $data[$index];
            }
            $filteredData[] = $row;
    	}
    	
    	$summarizedData = array();
    	$currentRow = $filteredData[0][0];
    	
    	for($i = 0; $i < count($filteredData); $i++)
    	{
            $row = array();
            $row[0] = $currentRow;
            $add = false;
            while($filteredData[$i][0] == $currentRow)
            {
                for($j = 1; $j < count($indices); $j++)
                {
                    $add = true;
                    $row[$j] += str_replace(",", "", $filteredData[$i][$j]);
                }
                $i++;
            }
            if($add) $summarizedData[] = $row;
            $currentRow = $filteredData[$i][0];
            $i--;
    	}
        $table = new TableContent($newHeaders, $summarizedData, $newParams);
        $table->setAutoTotals(true);
        $report->add($table);
    }

    /**
     * Recursively generates tables based on grouping parameters. The method
     * is called in a nested fashion hence the grouping can also be nested.
     * 
     * @param Array $params
     * @return Array
     */
    protected function generateTable($report, $params)
    {
        $groupingField = array_search($params["grouping_fields"][$params["grouping_level"]],$params["fields"]);
        $groupingLevel = $params["grouping_level"];
        $accumulatedTotals = array();
        
        if(count($this->reportData) == 0) return;
        
        do
        {
            if($_REQUEST["grouping_".($params["grouping_level"]+1)."_newpage"] == "1")
            {
                $report->addPage($_REQUEST["grouping_".($params["grouping_level"]+1)."_newpage"]);
            }

            $headingValue = $this->reportData[$this->reportDataIndex][$groupingField];
            $this->drawHeading($report, $headingValue);

            array_unshift($params["previous_headings"], array($headingValue, $groupingField));
            $params["ignored_fields"][] = $groupingField;

            if($params["grouping_fields"][$groupingLevel + 1] == "")
            {
                $data = array();
                do
                {
                    $continue = true;
                    $row = $this->reportData[$this->reportDataIndex];

                    @$data[] = array_values($row);

                    $this->reportDataIndex++;

                    foreach($params["previous_headings"] as $heading)
                    {
                        if($heading[0] != $this->reportData[$this->reportDataIndex][$heading[1]])
                        {
                            array_shift($params["previous_headings"]);
                            $continue = false;
                            break;
                        }
                    }
                }
                while($continue);
                $tableDetails = array(
                    'headers' => $params['headers'],
                    'data' => $data,
                    'params' => $params
                );
                $totals = $this->drawTable($report, $tableDetails);
                $params = $tableDetails['params'];
                array_pop($params["ignored_fields"]);
            }
            else
            {
                $params["grouping_level"]++;
                $totals = $this->generateTable($report, $params);
                array_shift($params["previous_headings"]);
                $params["grouping_level"]--;
                array_pop($params["ignored_fields"]);
            }
            
            if($this->drawTotals && $totals != null)
            {
                $totals[0] = 'Total';
                $totalsBox = new TableContent($params["headers"], $totals);
                $totalsBox->setAsTotalsBox(true);
                $params['widths'] = $this->widths;
                $totalsBox->setDataParams($params);
                
                $report->add($totalsBox);
                foreach($totals as $i => $total)
                {
                    if($total === null)
                    {
                        $accumulatedTotals[$i] = null;
                    }
                    else
                    {
                        $accumulatedTotals[$i] += $total;
                    }
                }
            }

            if($params["previous_headings"][0][0] != $this->reportData[$this->reportDataIndex][$params["previous_headings"][0][1]])
            {
                break;
            }

        }while($this->reportDataIndex < count($this->reportData));
        
        return $accumulatedTotals;        
    }

    public function getPermissions()
    {
        return array
        (
            array("label"=>"Can view","name"=>substr(str_replace("/", "_", $this->path),1)."_can_view"),
        );
    }

    public function getContents()
    {
        $form = $this->getForm();
        $form->setShowSubmit(false);
      
        $data = array(
            "filters" => $form->render(),
            "path" => $this->path
        );
        return $this->arbitraryTemplate(__DIR__ . "/reports.tpl", $data, true);
    }

    /**
     * Initializes the filters on the form.
     * @param integer $numFilters The total number of filters the form would display
     */
    protected function initializeFilters($numFilters)
    {
        $this->filters = new TableLayout($numFilters,4);
        $this->form->add($this->filters);
    }

    /**
     * Add a date filter to the form.
     * @param string $label A label for the filter
     * @param string $name  A machine readable name for the filter 
     */
    protected function addDateFilter($label,$name,$opt=null)
    {
        $this->filters
            ->add(Element::create("Label",$label),$this->numFilters,0)
            ->add(Element::create("SelectionList","","{$name}_option")
                ->addOption("Before","LESS")
                ->addOption("After","GREATER")
                ->addOption("On","EQUALS")
                ->addOption("Between","BETWEEN")
                ->setValue($opt),$this->numFilters,1)
            ->add(Element::create("DateField","","{$name}_start_date")->setId("{$name}_start_date"),$this->numFilters,2)
            ->add(Element::create("DateField","","{$name}_end_date")->setId("{$name}_end_date"),$this->numFilters,3);
            $this->numFilters++;
    }
    
    protected function addFieldFilter($label, $field, $options = array())
    {
        $this->filters->add(Element::create("Label", $label), $this->numFilters, 0);
        $this->filters->add($field, $this->numFilters, 2);
        $this->numFilters++;
    }

    protected function addReferencedFilter($label,$name,$model,$value,$searchField = true)
    {
        if($searchField === true || is_array($searchField))
        {
            if(is_array($searchField))
            {
                $enum_list = new ModelSearchField($model,$value);
            	foreach($searchField as $field)
                {
            	   $enum_list->addSearchField($field);
                }
                $enum_list->boldFirst = true;
            }
            else
            {
                $enum_list = new ModelSearchField($model,$value);
                $enum_list->boldFirst = false;
            }
        }
        else
        {
            $enum_list = new ModelField($model,$value);
        }
        $enum_list->setName("{$name}_value");
        $this->filters
            ->add(Element::create("Label",$label),$this->numFilters,0)
            ->add(Element::create("SelectionList","","{$name}_option")
                ->addOption("Is any of","IS_ANY_OF")
                ->addOption("Is none of","IS_NONE_OF")
                ->setValue("IS_ANY_OF"),$this->numFilters,1)
            ->add(Element::create("MultiFields")->setTemplate($enum_list),$this->numFilters,2);
        $this->numFilters++;
    }
    
    protected function addEnumerationFilter($label, $name, $options)
    {
        $enum_list = new SelectionList("","{$name}_value");
        $enum_list->setMultiple(true);
        
        foreach($options as $value =>$label) 
        {
            $enum_list->addOption($label,$value);
        }
        
        $this->filters
            ->add(Element::create("Label",str_replace("\\n"," ",$label)),$this->numFilters,0)
            ->add(Element::create("SelectionList","","{$name}_option")
            ->addOption("Is any of","INCLUDE")
            ->addOption("Is none of","EXCLUDE")
            ->setValue("INCLUDE"),$this->numFilters,1)
            ->add($enum_list,$this->numFilters,2);
        $this->numFilters++;
    }
    
    public abstract function getForm();
    
}
