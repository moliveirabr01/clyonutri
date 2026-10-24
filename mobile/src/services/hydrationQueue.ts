/**
 * NI2-92 (A6/A7): fila de registros de água que ainda não chegaram ao servidor.
 *
 * - Cada toque vira um item com chave própria (idempotency key) e o horário do toque (loggedAt).
 * - Toda nova tentativa reenvia a MESMA chave: se a primeira chegou e só a resposta se perdeu,
 *   o servidor devolve o registro existente (idempotent_replay) e nada duplica.
 * - Sem rede, tempo esgotado, 5xx, 429 ou sessão expirada (401): o item fica na fila.
 * - Recusa definitiva (outro 4xx, ex.: teto diário, data antiga demais): o item sai da fila
 *   e o erro volta para a tela mostrar.
 * - Web: guardada no localStorage (sobrevive a recarregar a página), separada por paciente.
 *   Nativo: só em memória enquanto o app está aberto (não há AsyncStorage no projeto).
 *
 * Lógica pura: armazenamento e envio são injetados, para testar sem rede.
 */
export interface QueuedHydration {
  key: string;
  amountMl: number;
  loggedAt: string;
  attempts: number;
  lastErrorKind?: string;
}

export interface QueueStorage {
  read(): string | null;
  write(value: string | null): void;
  readonly persistent: boolean;
}

export interface SendError { status?: number; kind?: string; code?: string; message?: string }

export type FlushOutcome<R> = {
  sent: Array<{ item: QueuedHydration; result: R }>;
  rejected: Array<{ item: QueuedHydration; error: unknown }>;
  remaining: QueuedHydration[];
  lastRetryableError: unknown | null;
};

const MAX_ITEMS = 50;

export function memoryStorage(): QueueStorage {
  let value: string | null = null;
  return { read: () => value, write: (v) => { value = v; }, persistent: false };
}

export function webStorage(patientId: number): QueueStorage | null {
  try {
    const ls: Storage | undefined = (globalThis as any).localStorage;
    if (!ls) return null;
    const name = `clyonutri.hydrationQueue.v1.${patientId}`;
    const probe = name + '.probe';
    ls.setItem(probe, '1'); ls.removeItem(probe);
    return {
      read: () => ls.getItem(name),
      write: (v) => { if (v === null) ls.removeItem(name); else ls.setItem(name, v); },
      persistent: true,
    };
  } catch {
    return null; // modo privado/bloqueado: cai para memória
  }
}

/** Erro que vale tentar de novo com a mesma chave. */
export function isRetryable(err: unknown): boolean {
  const e = (err ?? {}) as SendError;
  if (e.kind === 'offline' || e.kind === 'timeout' || e.kind === 'auth') return true;
  const s = typeof e.status === 'number' ? e.status : 0;
  return s === 0 || s === 401 || s === 408 || s === 429 || s >= 500;
}

export function newHydrationKey(patientId: number, now: number = Date.now(), rand: () => number = Math.random): string {
  const r = Math.floor(rand() * 36 ** 8).toString(36).padStart(8, '0');
  return `hyd-${patientId}-${now.toString(36)}-${r}`; // casa com ^[A-Za-z0-9._:-]{8,64}$
}

export class HydrationQueue<R = unknown> {
  private flushing: Promise<FlushOutcome<R>> | null = null;

  constructor(
    private readonly storage: QueueStorage,
    private readonly send: (item: QueuedHydration) => Promise<R>,
    private readonly patientId: number,
  ) {}

  get persistent(): boolean { return this.storage.persistent; }

  items(): QueuedHydration[] {
    try {
      const raw = this.storage.read();
      const parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed.filter((i) => i && typeof i.key === 'string' && typeof i.amountMl === 'number' && typeof i.loggedAt === 'string') : [];
    } catch {
      return [];
    }
  }

  pendingMl(): number { return this.items().reduce((sum, i) => sum + i.amountMl, 0); }

  private save(items: QueuedHydration[]): void {
    this.storage.write(items.length ? JSON.stringify(items.slice(-MAX_ITEMS)) : null);
  }

  /** Um toque = um item novo (chave nova). Reenvios do mesmo item nunca geram chave nova. */
  enqueue(amountMl: number, now: Date = new Date()): QueuedHydration {
    const item: QueuedHydration = { key: newHydrationKey(this.patientId, now.getTime()), amountMl, loggedAt: now.toISOString(), attempts: 0 };
    this.save([...this.items(), item]);
    return item;
  }

  /** Envia na ordem. Para no primeiro erro de rede (o resto também falharia). Uma execução por vez. */
  flush(): Promise<FlushOutcome<R>> {
    if (this.flushing) return this.flushing;
    this.flushing = (async () => {
      const out: FlushOutcome<R> = { sent: [], rejected: [], remaining: [], lastRetryableError: null };
      for (const item of this.items()) {
        try {
          const result = await this.send(item);
          out.sent.push({ item, result });
          this.save(this.items().filter((i) => i.key !== item.key));
        } catch (err) {
          if (isRetryable(err)) {
            out.lastRetryableError = err;
            const e = (err ?? {}) as SendError;
            this.save(this.items().map((i) => i.key === item.key ? { ...i, attempts: i.attempts + 1, lastErrorKind: e.kind ?? String(e.status ?? '') } : i));
            break;
          }
          out.rejected.push({ item, error: err });
          this.save(this.items().filter((i) => i.key !== item.key));
        }
      }
      out.remaining = this.items();
      return out;
    })().finally(() => { this.flushing = null; });
    return this.flushing;
  }
}
