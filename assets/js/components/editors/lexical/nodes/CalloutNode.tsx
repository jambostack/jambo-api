import { ElementNode, LexicalNode, SerializedElementNode, Spread } from 'lexical';
import { JSX } from 'react';

export type CalloutType = 'info' | 'warning' | 'success' | 'danger';

export type SerializedCalloutNode = Spread<{ type: CalloutType }, SerializedElementNode>;

const CALLOUT_ICONS: Record<CalloutType, string> = {
  info: 'ℹ️',
  warning: '⚠️',
  success: '✅',
  danger: '🚫',
};

const CALLOUT_COLORS: Record<CalloutType, { bg: string; border: string }> = {
  info:    { bg: '#eff6ff', border: '#3b82f6' },
  warning: { bg: '#fefce8', border: '#eab308' },
  success: { bg: '#f0fdf4', border: '#22c55e' },
  danger:  { bg: '#fef2f2', border: '#ef4444' },
};

export class CalloutNode extends ElementNode {
  __type: CalloutType;

  static getType(): string { return 'callout'; }
  static clone(node: CalloutNode): CalloutNode { return new CalloutNode(node.__type); }

  constructor(type: CalloutType = 'info', key?: string) {
    super(key);
    this.__type = type;
  }

  static importJSON(serializedNode: SerializedCalloutNode): CalloutNode {
    return $createCalloutNode(serializedNode.type).updateFromJSON(serializedNode);
  }

  exportJSON(): SerializedCalloutNode {
    return { ...super.exportJSON(), type: this.__type };
  }

  createDOM(): HTMLElement {
    const el = document.createElement('div');
    const c = CALLOUT_COLORS[this.__type];
    el.className = 'callout';
    el.setAttribute('data-type', this.__type);
    el.style.cssText = `margin:12px 0;padding:12px 16px;border-radius:8px;border-left:4px solid ${c.border};background:${c.bg};display:flex;gap:10px;align-items:flex-start;`;
    const icon = document.createElement('span');
    icon.className = 'callout-icon';
    icon.textContent = CALLOUT_ICONS[this.__type];
    icon.style.cssText = 'font-size:18px;flex-shrink:0;margin-top:1px;';
    el.appendChild(icon);
    const content = document.createElement('div');
    content.className = 'callout-content';
    content.style.cssText = 'flex:1;min-width:0;';
    el.appendChild(content);
    return el;
  }

  updateDOM(_prevNode: CalloutNode, dom: HTMLElement): boolean {
    const c = CALLOUT_COLORS[this.__type];
    dom.style.borderLeftColor = c.border;
    dom.style.background = c.bg;
    dom.setAttribute('data-type', this.__type);
    const iconEl = dom.querySelector('.callout-icon');
    if (iconEl) iconEl.textContent = CALLOUT_ICONS[this.__type];
    return false;
  }

  exportDOM(): { element: HTMLElement } {
    const el = this.createDOM();
    el.setAttribute('data-lexical-callout', 'true');
    return { element: el };
  }

  isShadowRoot(): boolean { return true; }
  canBeEmpty(): boolean { return false; }
}

export function $createCalloutNode(type: CalloutType = 'info'): CalloutNode {
  return new CalloutNode(type);
}

export function $isCalloutNode(node: LexicalNode | null | undefined): node is CalloutNode {
  return node instanceof CalloutNode;
}
