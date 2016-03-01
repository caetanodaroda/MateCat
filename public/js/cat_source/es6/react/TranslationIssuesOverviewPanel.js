export default React.createClass({

    getInitialState: function() {
        return this.getStateFromSid( this.props.sid );
    },

    componentWillReceiveProps : function( nextProps ) {
        console.log( nextProps );
        this.setState( this.getStateFromSid( nextProps.sid ) );
    }, 

    getStateFromSid : function(sid) {
        var segment = MateCat.db.segments.by('sid', sid);
        var original_target = this.getOriginalTarget( segment );

        return {
            segment         : segment,
            original_target : original_target,
            versions        : this.getVersions( sid )
        }

    },
    getVersions : function( sid ) {
        return MateCat.db.segment_versions.findObjects({
            id_segment : '' + sid
        });
    },

    getOriginalTarget : function( segment ) {
        var version_number = segment.version_number ;
        if ( version_number == "0" ) {
            return segment.translation ;
        }
        else {
            // query versions to find original target
            var root_version = MateCat.db.segment_versions.findObject({
                id_segment : '' + segment.sid,
                version_number : "0"
            });

            if (! root_version ) {
                throw 'Unable to find root version';
            }
            return root_version.translation ;
        }
    },

    originalTarget : function() {
        return { __html : UI.decodePlaceholdersToText( this.state.original_target ) };
    },

    render: function() {

        var sorted_versions = this.state.versions.sort(function(a,b) {
            return parseInt(a.version_number) < parseInt(b.version_number); 
        }); 

        var version_components = sorted_versions.map( function(v) {
            // if ( v.version_number != "0" ) {
            if ( true ) {
                return (<ReviewTranslationVersion key={v.id} versionId={v.id} 
                        versionNumber={v.version_number}  />); 
            }
        }.bind(this) ); 

        return <div className="review-issues-overview-panel"> 

            <div className="review-original-target-wrapper sidebar-block">
                <strong>Original target</strong>
                <div className="muted-text-box" dangerouslySetInnerHTML={this.originalTarget()} />
            </div>

            <ReviewCurrentVersion sid={this.props.sid} />
            {version_components}


        </div>
        ;
    }
});
