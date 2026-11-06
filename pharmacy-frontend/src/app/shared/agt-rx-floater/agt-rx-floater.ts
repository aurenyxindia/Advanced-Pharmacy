import { Component, input, output, signal, effect, HostListener } from '@angular/core';
import { CommonModule } from '@angular/common';
import { environment } from '../../../environments/environment';

export interface AgtRxFloaterModel {
  orderNo: string;
  prescriptionUrl: string;
}

@Component({
  selector: 'app-agt-rx-floater',
  imports: [CommonModule],
  templateUrl: './agt-rx-floater.html',
  styleUrl: './agt-rx-floater.scss',
})
export class AgtRxFloater {
  readonly rx = input<AgtRxFloaterModel | null>(null);
  readonly dismiss = output<void>();

  readonly zoom = signal(1);
  readonly panX = signal(0);
  readonly panY = signal(0);
  readonly collapsed = signal(false);
  readonly copyHint = signal<string | null>(null);

  private readonly minZoom = 0.5;
  private readonly maxZoom = 4;
  private readonly step = 0.25;

  private panning = false;
  private panStartX = 0;
  private panStartY = 0;
  private panOriginX = 0;
  private panOriginY = 0;

  constructor() {
    effect(() => {
      if (this.rx()?.prescriptionUrl) {
        this.resetView();
        this.collapsed.set(false);
      }
    });
  }

  absoluteUrl(raw: string): string {
    const u = (raw || '').trim();
    if (!u) return '';
    if (/^https?:\/\//i.test(u)) return u;
    const apiRoot = environment.apiUrl.replace(/\/+$/, '');
    const siteOrigin = apiRoot.replace(/\/api$/i, '');
    if (u.startsWith('/')) return siteOrigin + u;
    return `${siteOrigin}/${u.replace(/^\/+/, '')}`;
  }

  stageTransform(): string {
    return `translate(${this.panX()}px, ${this.panY()}px) scale(${this.zoom()})`;
  }

  isPdf(url: string): boolean {
    return String(url).toLowerCase().includes('.pdf');
  }

  zoomIn(): void {
    this.setZoom(Math.min(this.maxZoom, +(this.zoom() + this.step).toFixed(2)));
  }

  zoomOut(): void {
    this.setZoom(Math.max(this.minZoom, +(this.zoom() - this.step).toFixed(2)));
  }

  zoomReset(): void {
    this.resetView();
  }

  private setZoom(z: number): void {
    this.zoom.set(z);
    if (z <= 1) {
      this.panX.set(0);
      this.panY.set(0);
    }
  }

  private resetView(): void {
    this.zoom.set(1);
    this.panX.set(0);
    this.panY.set(0);
  }

  onWheel(event: WheelEvent): void {
    event.preventDefault();
    event.stopPropagation();
    const delta = event.deltaY > 0 ? -this.step : this.step;
    this.setZoom(Math.min(this.maxZoom, Math.max(this.minZoom, +(this.zoom() + delta).toFixed(2))));
  }

  onPanStart(event: MouseEvent): void {
    if (event.button !== 0 || this.zoom() <= 1) return;
    event.preventDefault();
    this.panning = true;
    this.panStartX = event.clientX;
    this.panStartY = event.clientY;
    this.panOriginX = this.panX();
    this.panOriginY = this.panY();
  }

  @HostListener('document:mousemove', ['$event'])
  onPanMove(event: MouseEvent): void {
    if (!this.panning) return;
    const dx = event.clientX - this.panStartX;
    const dy = event.clientY - this.panStartY;
    this.panX.set(this.panOriginX + dx);
    this.panY.set(this.panOriginY + dy);
  }

  @HostListener('document:mouseup')
  onPanEnd(): void {
    this.panning = false;
  }

  copyLink(): void {
    const url = this.absoluteUrl(this.rx()?.prescriptionUrl ?? '');
    if (!url) return;
    const done = (): void => {
      this.copyHint.set('Link copied');
      setTimeout(() => this.copyHint.set(null), 2000);
    };
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(url).then(done).catch(() => this.fallbackCopy(url, done));
    } else {
      this.fallbackCopy(url, done);
    }
  }

  openFull(): void {
    const url = this.absoluteUrl(this.rx()?.prescriptionUrl ?? '');
    if (!url) return;
    window.open(url, '_blank', 'noopener,noreferrer');
  }

  private fallbackCopy(text: string, done: () => void): void {
    try {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      done();
    } catch {
      prompt('Copy prescription link:', text);
    }
  }

  toggleCollapse(): void {
    this.collapsed.update((c) => !c);
  }

  close(): void {
    this.dismiss.emit();
  }
}
