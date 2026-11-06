import {
  Component,
  DestroyRef,
  OnInit,
  inject,
  output,
  signal,
} from '@angular/core';

const SPLASH_KEY = 'ap_splash_seen_v2';
const SPLASH_MS = 7800;

@Component({
  selector: 'app-splash-intro',
  templateUrl: './splash-intro.html',
  styleUrl: './splash-intro.scss',
})
export class SplashIntro implements OnInit {
  readonly closed = output<void>();
  readonly exiting = signal(false);
  readonly phase = signal(0);

  readonly bpm = signal(0);
  readonly spo2 = signal(0);
  readonly temp = signal(0);
  readonly status = signal('Initializing clinical systems…');
  readonly organ = signal('');

  private readonly destroyRef = inject(DestroyRef);
  private timers: ReturnType<typeof setTimeout>[] = [];
  private intervals: ReturnType<typeof setInterval>[] = [];
  private closedOnce = false;

  ngOnInit(): void {
    this.timers.push(
      setTimeout(() => this.phase.set(1), 180),
      setTimeout(() => {
        this.phase.set(2);
        this.status.set('Scanning human anatomy…');
        this.startVitals();
      }, 700),
      setTimeout(() => {
        this.phase.set(3);
        this.organ.set('heart');
        this.status.set('Heart rate stable · circulation mapped');
      }, 1600),
      setTimeout(() => {
        this.phase.set(4);
        this.organ.set('lungs');
        this.status.set('Respiratory pathway synchronized');
      }, 2800),
      setTimeout(() => {
        this.organ.set('liver');
        this.status.set('Pharmacy cascade · dosage pathways online');
      }, 4000),
      setTimeout(() => {
        this.phase.set(5);
        this.organ.set('system');
        this.status.set('Advanced Pharmacy Suite is ready');
      }, 5200),
      setTimeout(() => this.beginExit(), SPLASH_MS),
    );

    this.destroyRef.onDestroy(() => {
      this.timers.forEach(clearTimeout);
      this.intervals.forEach(clearInterval);
    });
  }

  skip(): void {
    this.beginExit();
  }

  private startVitals(): void {
    const bpmTarget = 72;
    const spo2Target = 98;
    const tempTarget = 36.6;
    let t = 0;

    const id = setInterval(() => {
      t += 1;
      const p = Math.min(1, t / 28);
      this.bpm.set(Math.round(bpmTarget * p + (Math.random() * 2 - 1)));
      this.spo2.set(Math.round(spo2Target * p));
      this.temp.set(Number((tempTarget * p).toFixed(1)));
      if (p >= 1) {
        this.bpm.set(bpmTarget);
        this.spo2.set(spo2Target);
        this.temp.set(tempTarget);
        clearInterval(id);
        this.startLivePulse();
      }
    }, 70);
    this.intervals.push(id);
  }

  private startLivePulse(): void {
    const id = setInterval(() => {
      const base = 72;
      this.bpm.set(base + Math.round(Math.random() * 3 - 1));
    }, 900);
    this.intervals.push(id);
  }

  private beginExit(): void {
    if (this.closedOnce) return;
    this.closedOnce = true;
    this.exiting.set(true);
    this.intervals.forEach(clearInterval);
    try {
      sessionStorage.setItem(SPLASH_KEY, '1');
    } catch {
      /* private mode */
    }
    this.timers.push(setTimeout(() => this.closed.emit(), 700));
  }

  static shouldShow(): boolean {
    try {
      return sessionStorage.getItem(SPLASH_KEY) !== '1';
    } catch {
      return true;
    }
  }
}
