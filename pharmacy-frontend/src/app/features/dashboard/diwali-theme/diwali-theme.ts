import { Component, signal } from '@angular/core';
import { CommonModule } from '@angular/common';

/** Greeting only — no dashboard data. Shown for the 2026 Deepavali days. */
interface DiwaliDay {
  key: string;
  title: string;
  line: string;
  marathi: string;
}

const MOTION_KEY = 'pp.dashboard.diwaliMotion';

const DAYS: Record<string, DiwaliDay> = {
  '2026-11-06': {
    key: 'dhanteras',
    title: 'Happy Dhanteras',
    line: 'The first lamps of Deepavali are lit. Wishing your pharmacy a steady and prosperous season.',
    marathi: 'धनतेरसच्या हार्दिक शुभेच्छा. दीपावलीची पहिली पणती आज पेटली आहे.',
  },
  '2026-11-07': {
    key: 'choti',
    title: 'Choti Diwali',
    line: 'Homes are dressed in light tonight. A calm evening before the main celebration.',
    marathi: 'छोटी दिवाळीच्या शुभेच्छा. आज संध्याकाळ उजेडात आहे.',
  },
  '2026-11-08': {
    key: 'diwali',
    title: 'Happy Diwali',
    line: 'Lakshmi Puja evening. May the festival of lights bring warmth to your counter and your team.',
    marathi: 'दिवाळीच्या हार्दिक शुभेच्छा. लक्ष्मीपूजनानिमित्त सुख आणि समृद्धी लाभो.',
  },
  '2026-11-09': {
    key: 'after',
    title: 'Deepavali',
    line: 'The lamps stay lit. A quiet day to carry yesterday’s wishes into the week.',
    marathi: 'दिव्यांचा उजेड कायम राहो. दीपावलीच्या शुभेच्छा.',
  },
  '2026-11-10': {
    key: 'govardhan',
    title: 'Govardhan Puja',
    line: 'A day of gratitude and plenty. Wishing your store a full shelf and a peaceful counter.',
    marathi: 'गोवर्धन पूजेच्या हार्दिक शुभेच्छा.',
  },
  '2026-11-11': {
    key: 'bhaidooj',
    title: 'Happy Bhai Dooj',
    line: 'The festival closes with family. Wishing the brothers and sisters on your team a good day.',
    marathi: 'भाऊबीजेच्या हार्दिक शुभेच्छा.',
  },
};

function localYmd(d = new Date()): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

@Component({
  selector: 'app-diwali-theme',
  imports: [CommonModule],
  templateUrl: './diwali-theme.html',
  styleUrl: './diwali-theme.scss',
})
export class DiwaliTheme {
  readonly day = DAYS[localYmd()] ?? null;
  readonly lamps = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13];
  readonly diyas = [0, 1, 2, 3, 4];
  /** Bursts scattered over the whole dashboard. */
  readonly crackers = [
    { x: '8%', y: '8%', d: '0s', c: '#fbbf24' },
    { x: '22%', y: '18%', d: '0.6s', c: '#ea580c' },
    { x: '78%', y: '10%', d: '1.1s', c: '#f59e0b' },
    { x: '90%', y: '22%', d: '0.3s', c: '#dc2626' },
    { x: '14%', y: '36%', d: '1.8s', c: '#f97316' },
    { x: '48%', y: '28%', d: '2.2s', c: '#fde68a' },
    { x: '66%', y: '40%', d: '0.9s', c: '#ea580c' },
    { x: '84%', y: '48%', d: '2.6s', c: '#fbbf24' },
    { x: '30%', y: '55%', d: '1.4s', c: '#dc2626' },
    { x: '55%', y: '62%', d: '3s', c: '#f59e0b' },
    { x: '12%', y: '72%', d: '2.1s', c: '#fbbf24' },
    { x: '74%', y: '70%', d: '0.4s', c: '#f97316' },
    { x: '40%', y: '82%', d: '2.8s', c: '#ea580c' },
    { x: '88%', y: '86%', d: '1.6s', c: '#fde68a' },
  ];
  /** Rockets rise from the bottom, then a burst opens where they peak. */
  readonly rockets = [
    { x: '10%', y: '16%', d: '0.2s', c: '#fbbf24' },
    { x: '27%', y: '24%', d: '1.4s', c: '#ea580c' },
    { x: '46%', y: '12%', d: '2.3s', c: '#fde68a' },
    { x: '63%', y: '20%', d: '0.8s', c: '#dc2626' },
    { x: '80%', y: '14%', d: '1.9s', c: '#f59e0b' },
    { x: '18%', y: '48%', d: '3.1s', c: '#f97316' },
    { x: '72%', y: '44%', d: '2.6s', c: '#fbbf24' },
    { x: '36%', y: '68%', d: '3.6s', c: '#ea580c' },
    { x: '88%', y: '60%', d: '1.1s', c: '#fde68a' },
  ];
  /** Entrance sweep runs once when the dashboard opens. */
  readonly entered = signal(false);
  /** Crackers begin after the page greeting is on screen. */
  readonly showCrackers = signal(false);
  readonly motion = signal(this.readMotion());
  private crackerTimer?: ReturnType<typeof setTimeout>;

  constructor() {
    if (this.day && this.motion()) {
      queueMicrotask(() => this.entered.set(true));
      this.queueCrackers();
    }
  }

  ngOnDestroy(): void {
    clearTimeout(this.crackerTimer);
  }

  toggleMotion(): void {
    const next = !this.motion();
    this.motion.set(next);
    clearTimeout(this.crackerTimer);
    this.showCrackers.set(false);
    if (next) {
      this.entered.set(true);
      this.queueCrackers();
    }
    try {
      localStorage.setItem(MOTION_KEY, next ? '1' : '0');
    } catch {
      /* private mode — in-memory toggle still works */
    }
  }

  private queueCrackers(): void {
    clearTimeout(this.crackerTimer);
    this.crackerTimer = setTimeout(() => {
      if (this.motion()) this.showCrackers.set(true);
    }, 1100);
  }

  private readMotion(): boolean {
    if (typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return false;
    }
    try {
      return localStorage.getItem(MOTION_KEY) !== '0';
    } catch {
      return true;
    }
  }
}
