import FunnelBlock from '@/components/funnel/FunnelBlock';
import type { Block as ContentBlock, Column as LayoutColumn } from '@/types/editor';
import { AlertCircle, GripVertical, Plus } from 'lucide-react';
import { useRef, useState } from 'react';
import { useDrag, useDrop } from 'react-dnd';
import { canAddChild, ContentBlockType, getAllowedChildren } from './validation/ContentValidation';

interface DropZoneProps {
    column: LayoutColumn;
    onBlockAdd: (columnId: string, block: ContentBlock) => void;
    onBlockUpdate: (columnId: string, blockId: string, updates: Partial<ContentBlock>) => void;
    onBlockDelete: (columnId: string, blockId: string) => void;
    onBlockMove?: (columnId: string, fromIndex: number, toIndex: number) => void;
    isSelected: boolean;
    onSelect: () => void;
    selectedBlockId?: string | null;
    onSelectBlock?: (blockId: string) => void;
}

const ItemTypes = {
    CONTENT_BLOCK: 'content_block',
    EXISTING_BLOCK: 'existing_block',
};

function createBlockFromDrop(dropItem: { type: string; defaultContent: Record<string, unknown> }): ContentBlock {
    return {
        id: `block-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`,
        type: dropItem.type as ContentBlock['type'],
        content: dropItem.defaultContent,
        settings: {
            padding: '16px',
            margin: '0 0 16px 0',
            backgroundColor: 'transparent',
            borderRadius: '0px',
        },
    };
}

function BlockRenderer({
    block,
    onDelete,
    isSelected,
    onSelect,
    onMove,
    index,
}: {
    block: ContentBlock;
    onDelete: () => void;
    isSelected: boolean;
    onSelect: () => void;
    onMove: (dragIndex: number, hoverIndex: number) => void;
    index: number;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [{ isDragging }, drag] = useDrag({
        type: ItemTypes.EXISTING_BLOCK,
        item: () => ({ id: block.id, index, type: ItemTypes.EXISTING_BLOCK }),
        collect: (monitor) => ({ isDragging: monitor.isDragging() }),
    });

    const [{ handlerId }, drop] = useDrop({
        accept: ItemTypes.EXISTING_BLOCK,
        collect: (monitor) => ({ handlerId: monitor.getHandlerId() }),
        hover(item, monitor) {
            if (!ref.current) return;
            const dragIndex = (item as any).index;
            const hoverIndex = index;
            if (dragIndex === hoverIndex) return;
            const hoverBoundingRect = ref.current.getBoundingClientRect();
            const hoverMiddleY = (hoverBoundingRect.bottom - hoverBoundingRect.top) / 2;
            const clientOffset = monitor.getClientOffset();
            const hoverClientY = (clientOffset?.y ?? 0) - hoverBoundingRect.top;
            if (dragIndex < hoverIndex && hoverClientY < hoverMiddleY) return;
            if (dragIndex > hoverIndex && hoverClientY > hoverMiddleY) return;
            onMove(dragIndex, hoverIndex);
            (item as any).index = hoverIndex;
        },
    });

    drag(drop(ref));

    const wrapperClass = `relative group ${isSelected ? 'ring-2 ring-primary' : 'hover:ring-1 hover:ring-primary/30'} ${isDragging ? 'opacity-50' : ''}`;

    return (
        <div
            ref={ref}
            data-handler-id={handlerId}
            className={wrapperClass}
            onClick={(event) => {
                event.stopPropagation();
                onSelect();
            }}
        >
            <div className="flex items-center">
                <div className="cursor-grab p-1 text-muted-foreground hover:text-foreground">
                    <GripVertical className="h-4 w-4" />
                </div>
                <div className="flex-1">
                    <FunnelBlock block={block} formDisabled />
                </div>
            </div>
            {isSelected && (
                <button
                    onClick={(event) => {
                        event.stopPropagation();
                        onDelete();
                    }}
                    className="absolute top-0 right-0 -mt-2 -mr-2 h-6 w-6 rounded-full bg-destructive text-xs text-destructive-foreground hover:bg-destructive/90"
                >
                    ×
                </button>
            )}
        </div>
    );
}

export default function ColumnDropZone({ column, onBlockAdd, onBlockUpdate, onBlockDelete, onBlockMove, isSelected, onSelect, selectedBlockId, onSelectBlock }: DropZoneProps) {
    const dropRef = useRef<HTMLDivElement>(null);
    const [validationError, setValidationError] = useState<string | null>(null);

    const blocks = column.blocks as unknown as ContentBlock[];
    const currentBlockTypes = blocks.map((block) => block.type as ContentBlockType);

    const [{ isOver, canDrop }, drop] = useDrop(
        () => ({
            accept: [ItemTypes.CONTENT_BLOCK, ItemTypes.EXISTING_BLOCK],
            canDrop: (item: { type: string; defaultContent?: Record<string, unknown> }) => {
                if (item.type === ItemTypes.EXISTING_BLOCK) return true;
                const childType = item.type as ContentBlockType;
                const validation = canAddChild('column', childType, currentBlockTypes);
                if (!validation.allowed) {
                    setValidationError(validation.reason || 'Cannot add this content here');
                    setTimeout(() => setValidationError(null), 3000);
                    return false;
                }
                return true;
            },
            drop: (item: { type: string; defaultContent?: Record<string, unknown> }) => {
                if (item.type === ItemTypes.EXISTING_BLOCK) {
                    return { columnId: column.id, reordered: true };
                }
                const newBlock = createBlockFromDrop(item as { type: string; defaultContent: Record<string, unknown> });
                onBlockAdd(column.id, newBlock);
                return { columnId: column.id };
            },
            collect: (monitor) => ({
                isOver: monitor.isOver(),
                canDrop: monitor.canDrop(),
            }),
        }),
        [column.id, currentBlockTypes],
    );

    drop(dropRef);

    const allowedChildren = getAllowedChildren('column');

    return (
        <div
            ref={dropRef}
            className={`relative min-h-[100px] p-2 transition-colors ${isSelected ? 'border-primary bg-primary/5' : ''} ${
                isOver && canDrop ? 'border-chart-2 bg-chart-2/10' : isOver && !canDrop ? 'border-destructive bg-destructive/10' : 'border-border'
            } border-2 border-dashed`}
            onClick={onSelect}
        >
            {validationError && (
                <div className="absolute top-2 right-2 z-10 flex items-center rounded bg-red-100 px-2 py-1 text-xs text-red-700">
                    <AlertCircle className="mr-1 h-3 w-3" />
                    {validationError}
                </div>
            )}

            {blocks.length === 0 ? (
                <div className="flex h-24 items-center justify-center text-muted-foreground">
                    <div className="text-center">
                        <Plus className="mx-auto mb-2 h-8 w-8 text-muted-foreground/50" />
                        <p className="text-sm">
                            Drop {allowedChildren.length > 3 ? `${allowedChildren.slice(0, 3).join(', ')}...` : allowedChildren.join(', ')} blocks
                            here
                        </p>
                        {isOver && canDrop && <p className="mt-1 text-xs text-green-600">Release to add block</p>}
                        {isOver && !canDrop && <p className="mt-1 text-xs text-red-600">This content is not allowed here</p>}
                    </div>
                </div>
            ) : (
                <div className="space-y-2">
                    {blocks.map((block, index) => (
                        <BlockRenderer
                            key={block.id}
                            block={block}
                            index={index}
                            onDelete={() => onBlockDelete(column.id, block.id)}
                            isSelected={selectedBlockId === block.id}
                            onSelect={() => onSelectBlock?.(block.id)}
                            onMove={(dragIndex, hoverIndex) => {
                                onBlockMove?.(column.id, dragIndex, hoverIndex);
                            }}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}
