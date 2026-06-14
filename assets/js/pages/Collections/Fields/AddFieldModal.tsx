import React from 'react';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import { useTranslation } from '@/lib/i18n';

import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { ScrollArea } from '@/components/ui/scroll-area';
import { TextCursor, AlignLeft, Link, AtSign, Lock, Hash, ListOrdered, CheckSquare, Droplet, Calendar, Clock, Image, GitBranch, Code } from 'lucide-react';

import fields from '@/lib/fields.json';

import FieldFormModal from './FieldFormModal';

interface AddFieldModalProps {
    isOpen: boolean;
    onClose: () => void;
    collectionId: number;
    projectId: number;
    projectUuid: string;
    collectionSlug: string;
    /** Surcharge le chemin d'API par défaut */
    apiBasePath?: string;
    onFieldCreated?: (createdField?: any) => void;
    collections: Array<{
        id: number;
        name: string;
    }>;
    collectionFields: Array<{
        id: number;
        name: string;
        label: string;
        type: string;
    }>;
    can: {
        create_field?: boolean;
        update_field?: boolean;
        delete_field?: boolean;
    };
}

export default function AddFieldModal({ isOpen, onClose, collectionId, projectId, projectUuid, collectionSlug, apiBasePath, onFieldCreated, collections, collectionFields, can }: AddFieldModalProps) {
    const t = useTranslation();
    const [selectedFieldType, setSelectedFieldType] = useState<string | null>(null);

    const handleFieldTypeSelect = (fieldType: string) => {
        setSelectedFieldType(fieldType);
    };

    // Fermeture simple (annulation) : ne déclenche PAS onFieldCreated.
    const handleFieldFormClose = () => {
        setSelectedFieldType(null);
        onClose();
    };

    // Sauvegarde réussie : propage le champ créé au parent (mise à jour locale).
    const handleFieldFormSaved = (createdField?: any) => {
        setSelectedFieldType(null);
        onClose();
        onFieldCreated?.(createdField);
    };

    return (
        <>
            <Dialog open={isOpen && !selectedFieldType} onOpenChange={onClose}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{t('fields.add_title')}</DialogTitle>
                        <DialogDescription className='sr-only'>
                            {t('fields.add_desc')}
                        </DialogDescription>
                    </DialogHeader>
                    <ScrollArea className="h-[400px] pr-4">
                        <div className="grid grid-cols-2 gap-4">
                            {Object.entries(fields).map(([type, field]) => (
                                <button
                                    key={type}
                                    onClick={() => handleFieldTypeSelect(type)}
                                    className="flex items-start space-x-3 rounded-lg border p-4 text-left transition-colors hover:bg-accent"
                                >
                                    <div className={cn('rounded-md p-2', field.bg)}>
                                        {React.createElement(
                                            {
                                                TextCursor,
                                                TextAlignLeft: AlignLeft,
                                                Link,
                                                AtSign,
                                                Lock,
                                                SortNumericUp: Hash,
                                                ListOrdered,
                                                CheckSquare,
                                                Tint: Droplet,
                                                Calendar,
                                                CalendarCheck: Clock,
                                                PhotoVideo: Image,
                                                ExchangeAlt: GitBranch,
                                                Code
                                            }[field.icon] || TextCursor,
                                            { className: 'text-white' }
                                        )}
                                    </div>
                                    <div>
                                        <h3 className="font-medium">{field.label}</h3>
                                        <p className="text-sm text-muted-foreground">{field.desc}</p>
                                    </div>
                                </button>
                            ))}
                        </div>
                    </ScrollArea>
                </DialogContent>
            </Dialog>

            {selectedFieldType && (
                <FieldFormModal
                    isOpen={!!selectedFieldType}
                    onClose={handleFieldFormClose}
                    fieldType={selectedFieldType}
                    collectionId={collectionId}
                    projectId={projectId}
                    projectUuid={projectUuid}
                    collectionSlug={collectionSlug}
                    apiBasePath={apiBasePath}
                    onFieldSaved={handleFieldFormSaved}
                    collections={collections}
                    collectionFields={collectionFields}
                    can={can}
                />
            )}
        </>
    );
} 