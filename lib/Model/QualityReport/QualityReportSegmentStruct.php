<?php
/**
 * Created by PhpStorm.
 * User: vincenzoruffa
 * Date: 24/07/2018
 * Time: 12:46
 */
use API\V2\Json\QALocalWarning;


class QualityReport_QualityReportSegmentStruct extends DataAccess_AbstractDaoObjectStruct implements DataAccess_IDaoStruct {


    public $sid;

    public $target;

    public $segment;

    public $segment_hash;

    public $raw_word_count;

    public $translation;

    public $version;

    public $ice_locked;

    public $status;

    public $time_to_edit;

    public $warning;

    public $suggestion_match;

    public $suggestion_source;

    public $suggestion;

    public $edit_distance;

    public $locked;

    public $match_type;

    /**
     * @return float
     */
    public function getSecsPerWord() {
        $val = @round( ( $this->time_to_edit / 1000 ) / $this->raw_word_count, 1 );
        return ( $val != INF ? $val : 0 );
    }

    public function isICEModified(){
        return ( $this->getPEE() != 0 && $this->isICE() );
    }

    public function isICE(){
        return ( $this->match_type == 'ICE' && $this->locked );
    }

    /**
     * @return float|int
     */
    public function getPEE() {

        $post_editing_effort = round(
                ( 1 - \MyMemory::TMS_MATCH(
                                self::cleanSegmentForPee( $this->suggestion ),
                                self::cleanSegmentForPee( $this->translation ),
                                $this->target
                        )
                ) * 100
        );

        if ( $post_editing_effort < 0 ) {
            $post_editing_effort = 0;
        } elseif ( $post_editing_effort > 100 ) {
            $post_editing_effort = 100;
        }

        return $post_editing_effort;

    }

    private static function cleanSegmentForPee( $segment ){
        $segment = htmlspecialchars_decode( $segment, ENT_QUOTES);

        return $segment;
    }

    public function getLocalWarning(){
        $QA = new \QA( $this->segment, $this->translation );
        $local_warning = new QALocalWarning($QA, $this->sid);
        return $local_warning->render();
    }
}