/**
 * SDK de Firebase Auth (ADR-002).
 * Config íntegramente por variables de entorno (VITE_FIREBASE_*), nada hardcodeado.
 * NO se inicializa Analytics (evita dependencias extra en Node/test).
 */
import { initializeApp } from 'firebase/app';
import {
  getAuth,
  GoogleAuthProvider,
  onAuthStateChanged,
  signInWithEmailAndPassword,
  signInWithPopup,
  signOut,
} from 'firebase/auth';

const firebaseConfig = {
  apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
  authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
  projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
  storageBucket: import.meta.env.VITE_FIREBASE_STORAGE_BUCKET,
  messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
  appId: import.meta.env.VITE_FIREBASE_APP_ID,
};

const app = initializeApp(firebaseConfig);

export const auth = getAuth(app);

export { onAuthStateChanged };

/** Google Sign-In con dominio sugerido planjuarez.org (RF-01, ADR-002). */
export async function loginGoogle(): Promise<void> {
  const provider = new GoogleAuthProvider();
  provider.setCustomParameters({ hd: 'planjuarez.org' });
  await signInWithPopup(auth, provider);
}

/** Login con email/password (respaldo para personas externas invitadas, ADR-002). */
export async function loginEmailPassword(email: string, password: string): Promise<void> {
  await signInWithEmailAndPassword(auth, email, password);
}

export async function logoutFirebase(): Promise<void> {
  await signOut(auth);
}
