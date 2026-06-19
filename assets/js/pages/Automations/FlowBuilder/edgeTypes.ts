import { EdgeProps, getBezierPath } from '@xyflow/react';

export function CustomEdge({
    id, sourceX, sourceY, targetX, targetY,
    sourcePosition, targetPosition, data, markerEnd,
}: EdgeProps) {
    const [edgePath] = getBezierPath({
        sourceX, sourceY, sourcePosition,
        targetX, targetY, targetPosition,
    });

    return (
        <>
            <path
                id={id}
                d={edgePath}
                fill="none"
                className="stroke-2 stroke-muted-foreground/40"
                markerEnd={markerEnd}
            />
            {data?.label && (
                <text>
                    <textPath href={`#${id}`} style={{ fontSize: 11 }} startOffset="50%" textAnchor="middle" className="fill-muted-foreground">
                        {data.label}
                    </textPath>
                </text>
            )}
        </>
    );
}

export const edgeTypes = {
    custom: CustomEdge,
};
