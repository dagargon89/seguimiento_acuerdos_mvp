/**
 * SDK de Firebase Auth (ADR-002).
 * Config íntegramente por variables de entorno (VITE_FIREBASE_*), nada hardcodeado.
 * NO se inicializa Analytics (evita dependencias extra en Node/test).
 */
import { initializeApp } from 'firebase/app';
import {
  createUserWithEmailAndPassword,
  EmailAuthProvider,
  getAuth,
  GoogleAuthProvider,
  onAuthStateChanged,
  reauthenticateWithCredential,
  signInWithEmailAndPassword,
  signInWithPopup,
  signOut,
  updatePassword,
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

/** Google Sign-In: cualquier cuenta de Google (el acceso lo gobierna la aprobación en Usuarios, ADR-006). */
export async function loginGoogle(): Promise<void> {
  const provider = new GoogleAuthProvider();
  await signInWithPopup(auth, provider);
}

/** Login con email/password (respaldo para personas externas invitadas, ADR-002). */
export async function loginEmailPassword(email: string, password: string): Promise<void> {
  await signInWithEmailAndPassword(auth, email, password);
}

/** Alta de cuenta con email/password para el autorregistro (ADR-006). */
export async function crearCuentaEmailPassword(email: string, password: string): Promise<void> {
  try {
    await createUserWithEmailAndPassword(auth, email, password);
  } catch (e: unknown) {
    const codigo = typeof e === 'object' && e !== null && 'code' in e ? String((e as { code: unknown }).code) : '';
    if (codigo === 'auth/email-already-in-use') {
      throw Object.assign(new Error('Ya existe una cuenta con este correo. Inicia sesión.'), { code: codigo });
    }
    if (codigo === 'auth/invalid-email') {
      throw Object.assign(new Error('El correo no es válido.'), { code: codigo });
    }
    if (codigo === 'auth/weak-password') {
      throw Object.assign(new Error('La contraseña debe tener al menos 6 caracteres.'), { code: codigo });
    }
    throw e;
  }
}

export async function logoutFirebase(): Promise<void> {
  await signOut(auth);
}

/** true si la cuenta actual inicia sesión con email/password (no Google). */
export function proveedorEsPassword(): boolean {
  return auth.currentUser?.providerData.some((p) => p.providerId === 'password') ?? false;
}

/** Cambio de contraseña autogestionado (reautentica y luego actualiza). */
export async function cambiarPassword(actual: string, nueva: string): Promise<void> {
  const user = auth.currentUser;
  if (!user?.email) {
    throw new Error('No hay una sesión activa con email/contraseña.');
  }
  try {
    const credencial = EmailAuthProvider.credential(user.email, actual);
    await reauthenticateWithCredential(user, credencial);
    await updatePassword(user, nueva);
  } catch (e: unknown) {
    const codigo = typeof e === 'object' && e !== null && 'code' in e ? String((e as { code: unknown }).code) : '';
    if (codigo === 'auth/wrong-password' || codigo === 'auth/invalid-credential') {
      throw new Error('La contraseña actual es incorrecta.');
    }
    if (codigo === 'auth/weak-password') {
      throw new Error('La nueva contraseña debe tener al menos 6 caracteres.');
    }
    if (codigo === 'auth/requires-recent-login') {
      throw new Error('Vuelve a iniciar sesión e inténtalo de nuevo.');
    }
    throw new Error('No se pudo actualizar la contraseña. Inténtalo de nuevo.');
  }
}
