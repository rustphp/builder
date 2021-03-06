<?php

namespace rustphp\builder\model;

use rustphp\builder\util\Config;
use rustphp\builder\util\OutputBuffer;

/**
 * Class GeneratePlanModel
 *
 * @package app\service\generator
 */
class GeneratePlanModel {
    protected $dataSource=null;
    protected $config    =null;
    protected $files     =[];
    protected $tables    =[];

    /**
     * GeneratePlanModel constructor.
     *
     * @param array  $data_source
     * @param Config $config
     * @param string $template_path
     */
    public function __construct($data_source, Config $config, $template_path) {
        $this->dataSource=$data_source;
        $this->config=$config;
        $this->initTemplate($template_path);
    }

    /**
     * @return $this
     */
    public function build() {
        $files= &$this->files;
        if (!$files) {
            return $this;
        }
        foreach ($files as $file=>$params) {
            $file_content='';
            $template_file=$params['template'];
            $models=$params['model'];
            $models=!is_array($models) ? [$models] : $models;
            try {
                foreach ($models as $model) {
                    OutputBuffer::start();
                    require($template_file);
                    $file_content.=OutputBuffer::getAndClean();
                }
            } catch (\Error $e) {
                var_dump($file);
                print_r($params);
                die($e->getMessage());
            } catch (\Exception $e) {
                var_dump($file);
                print_r($params);
                die($e->getMessage());
            }
            $files[$file]=$file_content;
        }
        return $this;
    }

    /**
     * @param string $output_path
     */
    public function output($output_path) {
        foreach ($this->files as $output_file=>$file) {
            $dir=dirname($output_path . $output_file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($output_path . $output_file, $file);
            echo "build ", $output_path, $output_file, " ok \n";
        }
    }

    /**
     * @return array
     */
    public function getGenerateFiles() {
        return $this->files;
    }

    /**
     * TODO:记录生成日志？
     *
     * @param String $templateFile
     * @param array  $config
     */
    protected function initGenerateFile(String $templateFile, Array $config) {
        $generatePath=$config['path']??null;
        if (!$generatePath) {
            return;
        }
        $baseConfig=$this->config->get('base', true);
        $files= &$this->files;
        $generateByProject=$config['generate_by_project']??false;
        $is_append=$config['is_append']??false;
        if (!$generateByProject) {
            $files[$generatePath]=[
                'template'=>$templateFile,
                'model'   =>new GenerateModel($baseConfig),
                'flags'   =>$is_append ? FILE_APPEND : 0,
            ];
            return;
        }
        $dataSource=$this->dataSource;
        $project=$this->config->get('project', true);
        $this->initProjectFiles($project, $dataSource, [
            'config'      =>$config,
            'baseConfig'  =>$baseConfig,
            'templateFile'=>$templateFile,
            'generatePath'=>$generatePath,
            'is_append'   =>$is_append,
        ]);
    }

    /**
     * 初始化项目文件
     *
     * @param array $project
     * @param array $dataSource
     * @param array $params
     */
    protected function initProjectFiles($project, $dataSource, $params) {
        $config=$params['config']??[];
        $baseConfig=$params['baseConfig']??[];
        $errorNumIncrease=($baseConfig['errorNumIncrease']??100) + 0;
        $projectErrorNumInitialized=($baseConfig['projectErrInitNum']??10000) + 0;
        unset($baseConfig['errorNumIncrease'], $baseConfig['projectErrInitNum']);
        $baseConfig['baseClass']=$config['base_class']??'';
        $generateInfo=[
            'path'    =>$params['generatePath']??null,
            'template'=>$params['templateFile']??null,
            'isAppend'=>$params['is_append']??false,
        ];
        //$files = &$this->files;
        foreach ($project as $module=>$functions) {
            $moduleInfo=explode('=', $module);
            $moduleId=isset($moduleInfo[0]) ? $moduleInfo[0] : $module;
            $moduleName=isset($moduleInfo[1]) ? $moduleInfo[1] : $module;
            foreach ($functions as $table=>$class_name) {
                $tableInfo=$dataSource[$table] ?? null;
                $generateInfo['params']=array_merge($baseConfig, [
                    'moduleName'     =>$moduleName,
                    'moduleId'       =>$moduleId,
                    'className'      =>$class_name,
                    'tableName'      =>$table,
                    'tableInfo'      =>$tableInfo,
                    'projectErrorNum'=>$projectErrorNumInitialized + 0
                ]);
                //文件名初始化
                $prefix=$config['prefix'] ?? '';
                $suffix=$config['suffix'] ?? '';
                $use_table_name=$config['use_table_name'] ?? false;
                $name=$use_table_name ? $table : $class_name;
                $fileName=$prefix . $name . $suffix;
                $useModule=$config['use_module']??0;
                //按类型生成
                $generateByTypes=$config['generate_by_types']??[];
                if ($generateByTypes) {
                    foreach ($generateByTypes as $typeName) {
                        $fileNameParams=1 == $useModule ? [$moduleName] : [];
                        array_push($fileNameParams, $fileName);
                        array_push($fileNameParams, $typeName);
                        $generateInfo['params']['type']=$typeName;
                        $generateInfo['file']=vsprintf($generateInfo['path'], $fileNameParams);
                        $this->generateModelFile($generateInfo, $this->files);
                    }
                } else {
                    $moduleParams=1 == $useModule ? [$moduleName] : [];
                    array_push($moduleParams, $fileName);
                    $generateInfo['file']=vsprintf($generateInfo['path'], $moduleParams);
                    $this->generateModelFile($generateInfo, $this->files);
                }
                $projectErrorNumInitialized+=$errorNumIncrease;
            }
        }
    }

    /**
     * 模板初始化
     *
     * @param String $templatePath
     */
    protected function initTemplate(String $templatePath) {
        $config=$this->config;
        $templates=$config->get('templates', true);
        foreach ($templates as $templateFile=>$template) {
            if (!$template) {
                continue;
            }
            $this->initGenerateFile($templatePath . $templateFile, $template);
        }
    }

    protected function generateFileName() {
    }

    /**
     * @param array $generateInfo
     * @param array $files
     */
    protected function generateModelFile(array $generateInfo, array &$files): void {
        $model=new GenerateModel($generateInfo['params']);
        $models=$model;
        $file=$generateInfo['file'];
        if ($generateInfo['isAppend']) {
            $models=$files[$file]['model']??[];
            $models[]=$model;
        }
        $files[$file]=[
            'template'=>$generateInfo['template'],
            'model'   =>$models,
        ];
    }
}