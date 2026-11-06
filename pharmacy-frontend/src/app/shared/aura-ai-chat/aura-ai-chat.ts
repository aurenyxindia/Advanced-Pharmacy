import {
  Component,
  ElementRef,
  OnDestroy,
  OnInit,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AuraAiChatMessage, AuraAiService } from '../../core/services/aura-ai.service';

interface PanelRect {
  x: number;
  y: number;
  w: number;
  h: number;
}

export interface AuraAiUiMessage {
  role: 'user' | 'assistant';
  content: string;
  error?: boolean;
}

@Component({
  selector: 'app-aura-ai-chat',
  imports: [CommonModule, FormsModule],
  templateUrl: './aura-ai-chat.html',
  styleUrl: './aura-ai-chat.scss',
})
export class AuraAiChat implements OnInit, OnDestroy {
  private readonly auraAi = inject(AuraAiService);
  private readonly nearBottomPx = 100;

  readonly messagesContainer = viewChild<ElementRef>('messagesContainer');
  readonly messageInput = viewChild<ElementRef>('messageInput');
  readonly chatWidget = viewChild<ElementRef<HTMLElement>>('chatWidget');

  readonly showIcon = signal(true);
  readonly isMinimized = signal(true);
  readonly isMaximized = signal(false);
  /** Pixel position when user dragged (null = default bottom-right). */
  readonly panelPos = signal<PanelRect | null>(null);

  readonly messages = signal<AuraAiUiMessage[]>([]);
  readonly isLoading = signal(false);
  readonly configured = signal(true);

  userInput = '';

  /** Floating icon position (user-dragged, persisted); null = default bottom-right. */
  readonly fabPos = signal<{ x: number; y: number } | null>(this.loadFabPos());
  readonly fabDragging = signal(false);
  private fabDrag: { id: number; startX: number; startY: number; ox: number; oy: number; moved: boolean } | null = null;
  private suppressFabClick = false;

  private savedBeforeMax: PanelRect | null = null;
  dragging = false;
  private dragPointerId = -1;
  private dragStartX = 0;
  private dragStartY = 0;
  private dragOrigin: PanelRect | null = null;

  private readonly onDocPointerMove = (e: PointerEvent): void => {
    const fd = this.fabDrag;
    if (fd && e.pointerId === fd.id) {
      const dx = e.clientX - fd.startX;
      const dy = e.clientY - fd.startY;
      if (!fd.moved && Math.hypot(dx, dy) < AuraAiChat.FAB_DRAG_THRESHOLD) return;
      fd.moved = true;
      this.fabDragging.set(true);
      this.fabPos.set(this.clampFab(fd.ox + dx, fd.oy + dy));
      return;
    }
    if (!this.dragging || e.pointerId !== this.dragPointerId) return;
    const origin = this.dragOrigin;
    if (!origin) return;
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    let x = e.clientX - this.dragStartX;
    let y = e.clientY - this.dragStartY;
    x = Math.max(8, Math.min(vw - origin.w - 8, x));
    y = Math.max(8, Math.min(vh - origin.h - 8, y));
    this.panelPos.set({ x, y, w: origin.w, h: origin.h });
  };

  private readonly onDocPointerUp = (e: PointerEvent): void => {
    const fd = this.fabDrag;
    if (fd && e.pointerId === fd.id) {
      this.fabDrag = null;
      if (fd.moved) {
        this.suppressFabClick = true;
        this.fabDragging.set(false);
        this.snapFabToEdge();
      }
      return;
    }
    if (!this.dragging || e.pointerId !== this.dragPointerId) return;
    this.stopDrag();
  };

  private readonly onWindowResize = (): void => {
    const p = this.fabPos();
    if (p) this.fabPos.set(this.clampFab(p.x, p.y));
  };

  private static readonly FAB_SIZE = 48;
  private static readonly FAB_MARGIN = 8;
  private static readonly FAB_DRAG_THRESHOLD = 5;
  private static readonly FAB_STORAGE_KEY = 'aura_ai_fab_pos';

  ngOnInit(): void {
    this.addWelcome();
    this.auraAi.status().subscribe({
      next: (s) => {
        this.configured.set(s.configured);
      },
      error: () => {
        this.configured.set(false);
      },
    });
    document.addEventListener('pointermove', this.onDocPointerMove);
    document.addEventListener('pointerup', this.onDocPointerUp);
    document.addEventListener('pointercancel', this.onDocPointerUp);
    window.addEventListener('resize', this.onWindowResize);
    this.onWindowResize();
  }

  ngOnDestroy(): void {
    document.removeEventListener('pointermove', this.onDocPointerMove);
    document.removeEventListener('pointerup', this.onDocPointerUp);
    document.removeEventListener('pointercancel', this.onDocPointerUp);
    window.removeEventListener('resize', this.onWindowResize);
  }

  onFabPointerDown(event: PointerEvent): void {
    if (event.button !== 0) return;
    const el = event.currentTarget as HTMLElement;
    const r = el.getBoundingClientRect();
    this.fabDrag = { id: event.pointerId, startX: event.clientX, startY: event.clientY, ox: r.left, oy: r.top, moved: false };
    el.setPointerCapture?.(event.pointerId);
  }

  /** A drag must not also open the chat. */
  onFabClick(): void {
    if (this.suppressFabClick) {
      this.suppressFabClick = false;
      return;
    }
    this.openChat();
  }

  fabStyle(): Record<string, string> {
    const p = this.fabPos();
    return p ? { left: `${p.x}px`, top: `${p.y}px`, right: 'auto', bottom: 'auto' } : {};
  }

  /** Icon parked on the left half → tooltip opens to the right. */
  fabOnLeft(): boolean {
    const p = this.fabPos();
    return !!p && p.x + AuraAiChat.FAB_SIZE / 2 < window.innerWidth / 2;
  }

  private clampFab(x: number, y: number): { x: number; y: number } {
    const m = AuraAiChat.FAB_MARGIN;
    const s = AuraAiChat.FAB_SIZE;
    return {
      x: Math.max(m, Math.min(window.innerWidth - s - m, x)),
      y: Math.max(m, Math.min(window.innerHeight - s - m, y)),
    };
  }

  private snapFabToEdge(): void {
    const p = this.fabPos();
    if (!p) return;
    const m = AuraAiChat.FAB_MARGIN;
    const s = AuraAiChat.FAB_SIZE;
    const x = p.x + s / 2 < window.innerWidth / 2 ? m : window.innerWidth - s - m;
    const next = this.clampFab(x, p.y);
    this.fabPos.set(next);
    try {
      localStorage.setItem(AuraAiChat.FAB_STORAGE_KEY, JSON.stringify(next));
    } catch {
      /* ignore */
    }
  }

  private loadFabPos(): { x: number; y: number } | null {
    try {
      const raw = localStorage.getItem(AuraAiChat.FAB_STORAGE_KEY);
      if (!raw) return null;
      const p = JSON.parse(raw) as { x?: unknown; y?: unknown };
      return typeof p.x === 'number' && typeof p.y === 'number' ? { x: p.x, y: p.y } : null;
    } catch {
      return null;
    }
  }

  /** Called from main layout top bar (Retail openAuraAI). */
  openChat(): void {
    this.showIcon.set(false);
    this.isMinimized.set(false);
    this.addWelcome();
    this.scheduleScrollToBottom();
    setTimeout(() => this.messageInput()?.nativeElement?.focus(), 100);
  }

  closeChat(): void {
    this.isMinimized.set(true);
    this.showIcon.set(true);
    this.isMaximized.set(false);
    this.savedBeforeMax = null;
  }

  toggleMinimize(): void {
    this.isMinimized.update((v) => !v);
    if (!this.isMinimized()) {
      this.scheduleScrollToBottom();
      setTimeout(() => this.messageInput()?.nativeElement?.focus(), 100);
    }
  }

  toggleMaximize(): void {
    const el = this.chatWidget()?.nativeElement;
    if (!el) return;

    if (this.isMaximized()) {
      this.isMaximized.set(false);
      if (this.savedBeforeMax) {
        this.panelPos.set({ ...this.savedBeforeMax });
      }
      this.savedBeforeMax = null;
      return;
    }

    const r = el.getBoundingClientRect();
    this.savedBeforeMax = {
      x: r.left,
      y: r.top,
      w: r.width,
      h: r.height,
    };
    this.isMaximized.set(true);
  }

  onDragHandlePointerDown(event: PointerEvent): void {
    if (this.isMaximized() || this.isMinimized()) return;
    event.preventDefault();
    const el = this.chatWidget()?.nativeElement;
    if (!el) return;

    const r = el.getBoundingClientRect();
    const pos: PanelRect = this.panelPos() ?? { x: r.left, y: r.top, w: r.width, h: r.height };
    this.panelPos.set(pos);
    this.dragOrigin = { ...pos };
    this.dragging = true;
    this.dragPointerId = event.pointerId;
    this.dragStartX = event.clientX - pos.x;
    this.dragStartY = event.clientY - pos.y;
    (event.target as HTMLElement).setPointerCapture?.(event.pointerId);
  }

  private stopDrag(): void {
    this.dragging = false;
    this.dragPointerId = -1;
    this.dragOrigin = null;
  }

  widgetStyle(): Record<string, string> {
    if (this.isMaximized()) {
      return {};
    }
    const p = this.panelPos();
    if (!p) {
      return {};
    }
    return {
      left: `${p.x}px`,
      top: `${p.y}px`,
      right: 'auto',
      bottom: 'auto',
      width: `${p.w}px`,
      height: `${p.h}px`,
    };
  }

  send(): void {
    const text = (this.userInput || '').trim();
    if (!text || this.isLoading()) return;

    this.userInput = '';
    this.messages.update((list) => [...list, { role: 'user', content: text }]);
    this.isLoading.set(true);
    this.scheduleScrollToBottom();

    const history: AuraAiChatMessage[] = this.messages()
      .filter((m) => !(m.role === 'user' && m.content === text))
      .slice(-20)
      .map((m) => ({ role: m.role, content: m.content }));

    this.auraAi.sendMessage(text, history).subscribe({
      next: (res) => {
        this.isLoading.set(false);
        if (res.error) {
          this.messages.update((list) => [
            ...list,
            {
              role: 'assistant',
              content: 'Sorry, an error occurred: ' + res.error,
              error: true,
            },
          ]);
        } else {
          this.messages.update((list) => [
            ...list,
            { role: 'assistant', content: res.text || 'No response.' },
          ]);
        }
        this.scheduleScrollToBottom();
      },
      error: () => {
        this.isLoading.set(false);
        this.messages.update((list) => [
          ...list,
          {
            role: 'assistant',
            content: 'Request failed. Please try again.',
            error: true,
          },
        ]);
        this.scheduleScrollToBottom();
      },
    });
  }

  private addWelcome(): void {
    if (this.messages().length === 0) {
      this.messages.set([
        {
          role: 'assistant',
          content:
            "Hi! I'm AuraAI, your pharmacy assistant. Ask about drug uses, side effects, dosage, or brand substitution. Example: \"Side effects of paracetamol?\" or \"Dose of amoxicillin for adults?\"",
        },
      ]);
    }
  }

  private scheduleScrollToBottom(): void {
    setTimeout(() => this.scrollToBottom(), 0);
    setTimeout(() => this.scrollToBottom(), 50);
  }

  private scrollToBottom(): void {
    try {
      const el = this.messagesContainer()?.nativeElement as HTMLElement | undefined;
      if (el) el.scrollTop = el.scrollHeight;
    } catch {
      /* ignore */
    }
  }
}
