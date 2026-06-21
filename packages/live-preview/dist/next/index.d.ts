interface UseLivePreviewArgs {
    initialData: Record<string, any>;
}
/**
 * Hook Next.js pour le Live Preview avec edition visuelle.
 *
 * Usage :
 *   const { data, isPreview, fieldProps } = useLivePreview({ initialData });
 *
 *   <h1 {...fieldProps('title', 'text')}>{data.title}</h1>
 */
export declare function useLivePreview({ initialData }: UseLivePreviewArgs): {
    data: Record<string, any>;
    isPreview: boolean;
    fieldProps: (slug: string, type?: string) => {
        'data-jambo-type'?: string | undefined;
        'data-jambo-field': string;
        'data-jambo-collection': string;
    };
};
export {};
