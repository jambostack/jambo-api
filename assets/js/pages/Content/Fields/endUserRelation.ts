import { Field as CollectionField } from '@/types';

/**
 * Colonnes EndUser affichées dans les champs relation (table de sélection
 * et table des éléments liés). Source unique partagée par RelationField
 * et RelationModal.
 */
export const END_USER_DISPLAY_FIELDS = [
    { name: 'email', label: 'Email', type: 'email' },
    { name: 'name', label: 'Name', type: 'text' },
    { name: 'status', label: 'Status', type: 'text' },
] as CollectionField[];

export const END_USER_MODAL_FIELDS = [
    ...END_USER_DISPLAY_FIELDS,
    { name: 'created_at', label: 'Created', type: 'datetime' },
] as CollectionField[];
