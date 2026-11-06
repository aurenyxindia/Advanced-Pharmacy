import { Component, signal } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { SplashIntro } from './shared/splash-intro/splash-intro';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, SplashIntro],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  readonly showSplash = signal(SplashIntro.shouldShow());

  onSplashClosed(): void {
    this.showSplash.set(false);
  }
}
