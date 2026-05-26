import { useState } from 'react';
import axios from 'axios';
import { useTranslation } from '@/lib/i18n';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Collection } from '@/types/index.d';
import InputError from '@/components/input-error';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    projectUuid: string;
    collection: Collection;
    onCollectionDeleted?: () => void;
}

export default function DeleteCollectionModal({ open, onOpenChange, projectId, projectUuid, collection, onCollectionDeleted }: Props) {
    const t = useTranslation();
    const [slug, setSlug] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        setError('');
        try {
            await axios.delete(`/api/projects/${projectUuid}/collections/${collection.slug}`);
            setSlug('');
            onOpenChange(false);
            onCollectionDeleted?.();
        } catch {
            setError(t('collections.delete_failed'));
        } finally {
            setProcessing(false);
        }
    };

    if (!collection) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{t('collections.delete')}</DialogTitle>
                    <DialogDescription>
                        {t('collections.delete_desc')}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <div>{t('collections.delete_confirm_before')} <span className="font-mono bg-muted px-1 py-0.5 rounded border border-input inline-block  select-all">{collection.slug}</span> {t('collections.delete_confirm_after')}</div>
                        <Input
                            id="slug"
                            value={slug}
                            onChange={(e) => setSlug(e.target.value)}
                            required
                            placeholder={t('collections.slug_placeholder')}
                        />
                        <InputError message={error} />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={processing || slug !== collection.slug}
                        >
                            {t('collections.delete')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
} 