import AsyncStorage from '@react-native-async-storage/async-storage';
import type { ReceiveDraft } from './receiving';

/**
 * A count in progress survives the app being closed or the signal dropping at the gate: it is
 * kept on the phone, per company and order, until it is recorded or discarded. Only the count
 * is kept — nothing is sent from here, and nothing is sent later on its own. Drafts go when the
 * person signs out (IdentityProvider clears `purchases.draft.*`) and expire after two days.
 */

const MAX_AGE_MS = 2 * 24 * 60 * 60 * 1000;
const keyFor = (cmpId: number, poId: number) => `purchases.draft.${cmpId}.${poId}`;

export async function loadDraft(cmpId: number, poId: number): Promise<ReceiveDraft | null> {
  try {
    const raw = await AsyncStorage.getItem(keyFor(cmpId, poId));
    if (!raw) return null;
    const draft = JSON.parse(raw) as ReceiveDraft;
    if (!draft || draft.poId !== poId || Date.now() - (draft.updatedAt ?? 0) > MAX_AGE_MS) {
      await AsyncStorage.removeItem(keyFor(cmpId, poId));
      return null;
    }
    return draft;
  } catch {
    return null;
  }
}

export async function saveDraft(cmpId: number, draft: ReceiveDraft): Promise<void> {
  try {
    await AsyncStorage.setItem(keyFor(cmpId, draft.poId), JSON.stringify(draft));
  } catch {
    // A full disk loses the safety net, not the count on screen.
  }
}

export async function dropDraft(cmpId: number, poId: number): Promise<void> {
  await AsyncStorage.removeItem(keyFor(cmpId, poId)).catch(() => undefined);
}
