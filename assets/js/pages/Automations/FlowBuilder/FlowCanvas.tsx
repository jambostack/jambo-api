import { useCallback, useRef } from 'react';
import {
    ReactFlow, Background, Controls, MiniMap, NodeTypes,
    useReactFlow,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { useFlowStore } from './FlowStore';
import { edgeTypes } from './edgeTypes';
import BaseNode from './nodes/BaseNode';

const nodeTypes: NodeTypes = {
    'trigger.content.created': BaseNode,
    'trigger.content.updated': BaseNode,
    'trigger.content.deleted': BaseNode,
    'trigger.content.status_changed': BaseNode,
    'trigger.schedule.cron': BaseNode,
    'trigger.webhook.inbound': BaseNode,
    'logic.condition': BaseNode,
    'logic.switch': BaseNode,
    'logic.and': BaseNode,
    'logic.or': BaseNode,
    'logic.not': BaseNode,
    'logic.delay': BaseNode,
    'logic.loop': BaseNode,
    'action.send_email': BaseNode,
    'action.call_webhook': BaseNode,
    'action.create_entry': BaseNode,
    'action.update_entry': BaseNode,
    'action.delete_entry': BaseNode,
    'action.send_notification': BaseNode,
    'action.publish_entry': BaseNode,
    'http.request': BaseNode,
    'http.response_to_json': BaseNode,
    'ai.llm_call': BaseNode,
    'ai.generate_text': BaseNode,
    'ai.summarize': BaseNode,
    'ai.translate': BaseNode,
    'ai.classify': BaseNode,
    'db.find_entries': BaseNode,
    'db.count_entries': BaseNode,
    'db.raw_query': BaseNode,
    'file.read_file': BaseNode,
    'file.upload_file': BaseNode,
    'file.delete_file': BaseNode,
    'transform.set': BaseNode,
    'transform.map': BaseNode,
    'transform.filter': BaseNode,
    'transform.reduce': BaseNode,
    'transform.flatten': BaseNode,
    'transform.pick': BaseNode,
    'transform.omit': BaseNode,
    'transform.pluck': BaseNode,
    'transform.template': BaseNode,
    'util.log': BaseNode,
    'util.noop': BaseNode,
    'util.wait': BaseNode,
    'util.merge': BaseNode,
    'util.split': BaseNode,
    'util.comment': BaseNode,
};

function FlowCanvasInner() {
    const {
        nodes, edges, onNodesChange, onEdgesChange, onConnect,
        addNode, minimapVisible,
    } = useFlowStore();

    const { screenToFlowPosition } = useReactFlow();
    const canvasRef = useRef<HTMLDivElement>(null);

    const onDragOver = useCallback((event: React.DragEvent) => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    }, []);

    const onDrop = useCallback(
        (event: React.DragEvent) => {
            event.preventDefault();
            const type = event.dataTransfer.getData('application/reactflow-type');
            const label = event.dataTransfer.getData('application/reactflow-label');
            const icon = event.dataTransfer.getData('application/reactflow-icon');
            if (!type) return;

            const position = screenToFlowPosition({ x: event.clientX, y: event.clientY });
            const id = `n_${Date.now()}_${Math.random().toString(36).substr(2, 6)}`;

            addNode({
                id,
                type,
                position,
                data: { label, config: {}, icon, type },
            });
        },
        [screenToFlowPosition, addNode],
    );

    return (
        <div ref={canvasRef} className="flex-1 h-full" onDragOver={onDragOver} onDrop={onDrop}>
            <ReactFlow
                nodes={nodes}
                edges={edges}
                onNodesChange={onNodesChange}
                onEdgesChange={onEdgesChange}
                onConnect={onConnect}
                nodeTypes={nodeTypes}
                edgeTypes={edgeTypes}
                fitView
                deleteKeyCode={['Backspace', 'Delete']}
                multiSelectionKeyCode="Shift"
                snapToGrid
                snapGrid={[15, 15]}
            >
                <Background gap={15} size={0.5} color="var(--border)" />
                <Controls
                    className="[&>button]:!bg-card [&>button]:!border-border [&>button]:!text-foreground [&>button>svg]:!fill-foreground hover:[&>button]:!bg-accent"
                />
                {minimapVisible && (
                    <MiniMap
                        nodeStrokeWidth={2}
                        pannable
                        zoomable
                        className="!bg-card !border-border"
                        maskColor="hsl(var(--background) / 0.7)"
                        style={{ width: 180, height: 120 }}
                    />
                )}
            </ReactFlow>
        </div>
    );
}

export default function FlowCanvas() {
    return <FlowCanvasInner />;
}
