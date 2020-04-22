/**
 * React Component for the editarea.

 */
import React  from 'react';
import $  from 'jquery';
import SegmentConstants  from '../../constants/SegmentConstants';
import SegmentStore  from '../../stores/SegmentStore';
import Immutable  from 'immutable';
import EditArea  from './utils/editarea';
import TagUtils  from '../../utils/tagUtils';
import Speech2Text from '../../utils/speech2text';
import EventHandlersUtils  from './utils/eventsHandlersUtils';
import TextUtils from "../../utils/textUtils";

import {findWithRegex, encodeContent, getEntities} from "./utils/ContentEncoder";
import {CompositeDecorator, convertFromRaw, convertToRaw, Editor, EditorState} from "draft-js";
import TagEntity from "./TagEntity/TagEntity.component";


class Editarea extends React.Component {

    constructor(props) {
        super(props);

        const decorator = new CompositeDecorator([
            {
                strategy: getEntityStrategy('IMMUTABLE'),
                component: TagEntity,
                props: {
                    onClick: this.onEntityClick
                }
            }
        ]);

        // Inizializza Editor State con solo testo
        const plainEditorState = EditorState.createEmpty(decorator);
        const rawEncoded = encodeContent(plainEditorState, this.props.translation);


        this.state = {
            translation: this.props.translation,
            editorState: rawEncoded,
            editAreaClasses : ['targetarea']
        };
        this.onChange = (editorState) => this.setState({editorState});
    }

    //Receive the new translation and decode it for draftJS
    setNewTranslation = (sid, translation) => {
        this.state = {
            translation: translation
        };
    };

    updateTranslationInStore = () => {
        let translation; // Retrieve the translation from draftJS and send it to the Store
        SegmentActions.updateTranslation(this.props.segment.sid, translation)
    };

    componentDidMount() {
        SegmentStore.addListener(SegmentConstants.REPLACE_TRANSLATION, this.setNewTranslation);
    }

    componentWillUnmount() {
        SegmentStore.removeListener(SegmentConstants.REPLACE_TRANSLATION, this.setNewTranslation);
    }

    // shouldComponentUpdate(nextProps, nextState) {}

    // getSnapshotBeforeUpdate(prevProps) {}

    componentDidUpdate(prevProps, prevState, snapshot) {}

    render() {
        const {editorState} = this.state;
        const {onChange} = this;
        const contentState = editorState.getCurrentContent();

        // Affidabile solo per il numero delle entità presenti, ma non per le chiavi
        const entityKeys = Object.keys(convertToRaw(contentState).entityMap);
        console.log('Entità presenti nell\'editor: ', entityKeys.length);

        let lang = '';
        let readonly = false;
        if (this.props.segment){
            lang = config.target_rfc.toLowerCase();
            readonly = (this.props.readonly || this.props.locked || this.props.segment.muted || !this.props.segment.opened);
        }
        let classes = this.state.editAreaClasses.slice();
        if (this.props.locked || this.props.readonly) {
            classes.push('area')
        } else {
            classes.push('editarea')
        }

        return <div className={classes.join(' ')}
                    ref={(ref) => this.editAreaRef = ref}
                    id={'segment-' + this.props.segment.sid + '-editarea'}
                    data-sid={this.props.segment.sid}
                    tabIndex="-1"
        >
            <Editor
                lang={lang}
                editorState={editorState}
                onChange={onChange}
                ref={(el) => this.editor = el}
                readOnly={readonly}
            />
        </div>;
    }
}

function getEntityStrategy(mutability, callback) {
    return function (contentBlock, callback, contentState) {
        contentBlock.findEntityRanges(
            (character) => {
                const entityKey = character.getEntity();
                if (entityKey === null) {
                    return false;
                }
                return contentState.getEntity(entityKey).getMutability() === mutability;
            },
            callback
        );
    };
}

export default Editarea ;

