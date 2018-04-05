<?php

use AbstractControllers\IController;
use API\V2\Exceptions\AuthenticationError;
use Exceptions\ValidationError;
use Features\BaseFeature;
use Features\Dqf;
use Features\IBaseFeature;

/**
 * Created by PhpStorm.
 * User: fregini
 * Date: 3/11/16
 * Time: 11:00 AM
 */
class FeatureSet {

    private $features = array();

    /**
     * Initializes a new FeatureSet. If $features param is provided, FeaturesSet is populated with the given params.
     * Otherwise it is populated with mandatory features.
     *
     * @param $features
     *
     * @throws Exception
     */
    public function __construct( $features = null ) {
        if ( is_null( $features ) ) {
            $this->__loadFromMandatory();
        } else {
            if ( !is_array( $features ) ) {
                throw new Exception('features params should be an array');
            }
            $this->features = $features ;
        }
    }

    /**
     * @return array
     */
    public function getCodes() {
        return array_values( array_map( function( $feature ) { return $feature->feature_code ; }, $this->features) );
    }

    /**
     * @param $string
     *
     * @throws Exception
     */
    public function loadFromString( $string ) {
        $this->loadFromCodes( FeatureSet::splitString( $string ) ) ;
    }

    /**
     * @param $feature_codes
     *
     * @throws Exception
     */
    private function loadFromCodes( $feature_codes ) {
        $features = array();

        if ( !empty( $feature_codes ) ) {
            foreach( $feature_codes as $code ) {
                $features [] = new BasicFeatureStruct( array( 'feature_code' => $code ) );
            }

            $this->merge( $features ) ;
        }
    }

    /**
     * Features are attached to project via project_metadata.
     *
     * @param Projects_ProjectStruct $project
     *
     * @return void
     * @throws Exception
     */
     public function loadForProject( Projects_ProjectStruct $project ) {
         $this->clear();
         $this->loadAutoActivableMandatoryFeatures();
         $this->loadFromString($project->getMetadataValue( Projects_MetadataDao::FEATURES_KEY  ) );
    }

    public function clear() {
         $this->features = [];
    }

    /**
     * @param $metadata
     *
     * @throws Exception
     * @throws Exceptions_RecordNotFound
     * @throws ValidationError
     */
    public function loadProjectDependenciesFromProjectMetadata( $metadata ) {
        $project_dependencies = [];
        $project_dependencies = $this->filter('filterProjectDependencies', $project_dependencies, $metadata );
        $features = [] ;
        foreach( $project_dependencies as $dependency ) {
            $features [ $dependency ] = new BasicFeatureStruct( array( 'feature_code' => $dependency ) );
        }

        $this->merge( $features );
    }

    /**
     *
     * @param $id_customer
     *
     * @throws Exception
     */
    public function loadFromUserEmail( $id_customer ) {
        $features = OwnerFeatures_OwnerFeatureDao::getByIdCustomer( $id_customer );
        $this->merge( $features );
    }

    /**
     * Loads featurs that can be acivated automatically on project, reading from
     * the list of MANDATORY_PLUGIN ( config.ini )
     */
    public function loadAutoActivableMandatoryFeatures() {

        $returnable =  array_filter($this->features, function( BasicFeatureStruct $feature) {
            $concreteClass = $feature->toNewObject();
            return $concreteClass->isAutoActivableOnProject();
        }) ;

        $this->merge( $returnable );
    }

    /**
     * Loads features that can be activated automatically on proejct, i.e. those that
     * don't require a parameter to be passed from the UI.
     *
     * This functions does some transformation in order to leverage `autoActivateOnProject()` function
     * which is defined on the concrete feature class.
     *
     * So it does the following:
     *
     * 1. find all owner_features for the given user
     * 2. instantiate a concrete feature class for each record
     * 3. filter the list based on the return of autoActivateOnProject()
     * 4. populate the featureSet with the resulting OwnerFeatures_OwnerFeatureStruct
     *
     * @param $id_customer
     *
     * @throws Exception
     */
    public function loadAutoActivableOwnerFeatures( $id_customer ) {
        $features = OwnerFeatures_OwnerFeatureDao::getByIdCustomer( $id_customer );

        $objs = array_map( function( $feature ) {
            /* @var $feature BasicFeatureStruct */
            return $feature->toNewObject();
        }, $features ) ;

        $returnable =  array_filter($objs, function( BaseFeature $obj ) {
            return $obj->isAutoActivableOnProject();
        }) ;

        $this->merge( array_map( function( BaseFeature $feature ) {
            return $feature->getFeatureStruct();
        }, $returnable ) ) ;
    }

    /**
     * Returns the filtered subject variable passed to all enabled features.
     *
     * @param $method
     * @param $filterable
     *
     * @return mixed
     *
     * FIXME: this is not a real filter since the input params are not passed
     * modified in cascade to the next function in the queue.
     * @throws Exceptions_RecordNotFound
     * @throws ValidationError
     * @internal param $id_customer
     */
    public function filter($method, $filterable) {
        $args = array_slice( func_get_args(), 1);

        foreach( $this->features as $feature ) {
            /* @var $feature BasicFeatureStruct */
            $obj = $feature->toNewObject();

            if ( !is_null( $obj ) ) {
                if ( method_exists( $obj, $method ) ) {
                    array_shift( $args );
                    array_unshift( $args, $filterable );

                    try {
                        /**
                         * There may be the need to avoid a filter to be executed before or after other ones.
                         * To solve this problem we could always pass last argument to call_user_func_array which
                         * contains a list of executed feature codes.
                         *
                         * Example: $args + [ $executed_features ]
                         *
                         * This way plugins have the chance to decide wether to change the value, throw an exception or
                         * do whatever they need to based on the behaviour of the other features.
                         *
                         */
                        $filterable = call_user_func_array( array( $obj, $method ), $args );
                    } catch ( ValidationError $e ) {
                        throw $e ;
                    } catch ( Exceptions_RecordNotFound $e ) {
                        throw $e ;
                    } catch ( AuthenticationError $e ) {
                        throw $e ;
                    } catch ( Exception $e ) {
                        Log::doLog("Exception running filter " . $method . ": " . $e->getMessage() );
                    }
                }
            }
        }

        return $filterable ;
    }


    /**
     * @param $method
     */
    public function run( $method ) {
        $args = array_slice( func_get_args(), 1 );

        foreach ( $this->sortFeatures()->features as $feature ) {
            $this->runOnFeature($method, $feature, $args);
        }
    }

    /**
     * appendDecorators
     *
     * Loads feature specific decorators, if any is found.
     *
     * Also, gives a last chance to plugins to define a custom decorator class to be
     * added to any call.
     *
     * @param string $name name of the decorator to activate
     * @param IController $controller the controller to work on
     * @param PHPTAL $template the PHPTAL view to add properties to
     *
     */
    public function appendDecorators( $name, IController $controller, PHPTAL $template ) {

        /** @var BasicFeatureStruct $feature */
        foreach( $this->sortFeatures()->features as $feature ) {

            $cls = Features::getFeatureClassDecorator( $feature, $name );
            if( !empty( $cls ) ){
                /** @var AbstractDecorator $obj */
                $obj = new $cls( $controller, $template );
                $obj->decorate();
            }

        }

    }

    /**
     * This function ensures that whenever DQF is present, dependent features always come before.
     * TODO: conver into something abstract.
     */
    public function sortFeatures() {
        $codes = $this->getCodes() ;

        foreach( $this->features as $feature ){
            /**
             * @var $feature BasicFeatureStruct
             */
            $baseFeature = $feature->toNewObject();
            $missing_dependencies = array_diff( $baseFeature::getDependencies(), $codes ) ;
            $this->loadFromCodes( $missing_dependencies );

            uasort( $this->features, function ( BasicFeatureStruct $left, BasicFeatureStruct $right ) use ( $baseFeature ) {
                if ( in_array( $left->feature_code, $baseFeature::getDependencies() ) ) {
                    return 0;
                } else {
                    return 1;
                }
            } );

        }

        return $this;
    }

    /**
     * Updates the features array with new features. Ensures no duplicates are created.
     * Loads dependencies as needed.
     *
     * @param $new_features BasicFeatureStruct[]
     *
     * @throws Exception
     */
    private function merge( $new_features ) {
        // first round, load dependencies

        $all_features = [] ;
        $conflictingDeps = [] ;

        foreach( $new_features as $feature ) {
            // flat dependency management

            $baseFeature     = $feature->toNewObject();

            $conflictingDeps[ $feature->feature_code ] = $baseFeature::getConflictingDependencies();

            $deps = array_map( function( $code ) {
                return new BasicFeatureStruct(['feature_code' => $code ]);
            }, $baseFeature->getDependencies() );


            $all_features = array_merge( $all_features, $deps, [$feature]  ) ;
        }

        /** @var BasicFeatureStruct $feature */
        foreach ( $all_features as $feature ) {
            foreach ( $conflictingDeps as $key => $value ) {
                if ( in_array( $feature->feature_code, $value ) ) {
                    throw new Exception( "{$feature->feature_code} is conflicting with $key." );
                }
            }
            if ( !isset( $this->features[ $feature->feature_code ] ) ) {
                $this->features[ $feature->feature_code ] = $feature;
            }
        }

    }

    public static function splitString( $string ) {
        return array_filter( explode(',', trim( $string ) ) ) ;
    }

    /**
     * Loads plugins into the FeatureSet from the list of mandatory plugins.
     *
     * @return BasicFeatureStruct[]|array
     *
     */
    private function __loadFromMandatory() {
        $features = [] ;

        if ( !empty( INIT::$AUTOLOAD_PLUGINS ) )  {
            foreach( INIT::$AUTOLOAD_PLUGINS as $plugin ) {
                $features[] = new BasicFeatureStruct( [ 'feature_code' => $plugin ] );
            }
        }

        $this->merge( $features ) ;
    }

    /**
     * Runs a command on a single feautre
     *
     * @param $method
     * @param $feature
     * @param $args
     */
    private function runOnFeature($method, BasicFeatureStruct $feature, $args) {
        $name = Features::getPluginClass( $feature->feature_code );

        if ( $name ) {
            $obj = new $name($feature);

            if (method_exists($obj, $method)) {
                call_user_func_array(array($obj, $method), $args);
            }
        }
    }

}