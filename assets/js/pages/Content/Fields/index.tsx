import { Field } from '@/types';

import TextField from './TextField';
import LongTextField from './LongTextField';
import RichTextField from './RichTextField';
import SlugField from './SlugField';
import EmailField from './EmailField';
import PasswordField from './PasswordField';
import NumberField from './NumberField';
import EnumerationField from './EnumerationField';
import BooleanField from './BooleanField';
import ColorField from './ColorField';
import DateField from './DateField';
import TimeField from './TimeField';
import MediaField from './MediaField';
import RelationField from './RelationField';
import JSONField from './JSONField';

export type FieldRendererProps = {
    field: Field;
    value: any;
    onChange: (field: Field, value: any, index?: number) => void;
    processing: boolean;
    errors: Record<string, string>;
};

export function renderField(props: FieldRendererProps) {
    const { field } = props;

    switch (field.type) {
        case 'text':
            return <TextField {...props} />;
        case 'longtext':
            return <LongTextField {...props} />;
        case 'richtext':
            return <RichTextField {...props} />;
        case 'slug':
            return <SlugField {...props} />;
        case 'email':
            return <EmailField {...props} />;
        case 'password':
            return <PasswordField {...props} />;
        case 'number':
            return <NumberField {...props} />;
        case 'enumeration':
            return <EnumerationField {...props} />;
        case 'boolean':
            return <BooleanField {...props} />;
        case 'color':
            return <ColorField {...props} />;
        case 'date':
        case 'datetime':
            return <DateField {...props} />;
        case 'time':
            return <TimeField {...props} />;
        case 'media':
            return <MediaField {...props} />;
        case 'relation':
            return <RelationField {...props} />;
        case 'json':
            return <JSONField {...props} />;
        default:
            return null;
    }
}

export {
    TextField,
    LongTextField,
    RichTextField,
    SlugField,
    EmailField,
    PasswordField,
    NumberField,
    EnumerationField,
    BooleanField,
    ColorField,
    DateField,
    TimeField,
    MediaField,
    RelationField,
    JSONField
}; 