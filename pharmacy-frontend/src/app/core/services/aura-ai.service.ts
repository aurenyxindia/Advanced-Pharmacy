import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map, catchError, of } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface AuraAiChatMessage {
  role: 'user' | 'assistant';
  content: string;
}

@Injectable({ providedIn: 'root' })
export class AuraAiService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/aura-ai`;

  status(): Observable<{ configured: boolean; provider: string; message?: string | null }> {
    return this.http
      .get<ApiResponse<{ configured: boolean; provider: string; message?: string | null }>>(
        `${this.base}/status`,
      )
      .pipe(map((r) => r.data));
  }

  sendMessage(
    message: string,
    history: AuraAiChatMessage[] = [],
  ): Observable<{ text: string; error?: string }> {
    return this.http
      .post<ApiResponse<{ text: string }>>(`${this.base}/chat`, {
        message,
        history: history.map((m) => ({ role: m.role, content: m.content })),
      })
      .pipe(
        map((r) => ({ text: r.data?.text ?? '' })),
        catchError((err) => {
          const body = err.error;
          const msg =
            (typeof body?.message === 'string' && body.message) ||
            (typeof body?.error === 'string' && body.error) ||
            err.message ||
            'AI request failed';
          return of({ text: '', error: msg });
        }),
      );
  }
}
