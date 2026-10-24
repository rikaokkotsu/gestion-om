import type { AnomalyPriorityCode, AnomalyStateCode } from '@/types';

/** Couleur de l'état : à décider, à suivre, terminé. */
export const stateClasses: Record<AnomalyStateCode, string> = {
    PROPOSED: 'bg-amber-100 text-amber-900',
    CONFIRMED: 'bg-red-100 text-red-800',
    TRANSMITTED: 'bg-sky-100 text-sky-800',
    DISMISSED: 'bg-slate-100 text-slate-600',
    CLOSED: 'bg-emerald-50 text-emerald-800',
};

export const priorityClasses: Record<AnomalyPriorityCode, string> = {
    HIGH: 'text-red-700',
    MEDIUM: 'text-amber-700',
    LOW: 'text-slate-600',
};

/** Libellé du bouton qui mène à un état, selon l'état de départ. */
export function actionLabel(from: AnomalyStateCode, to: AnomalyStateCode): string {
    switch (to) {
        case 'CONFIRMED':
            return from === 'PROPOSED' ? 'Confirmer' : 'Rouvrir';
        case 'DISMISSED':
            return 'Écarter';
        case 'TRANSMITTED':
            return 'Transmettre';
        case 'CLOSED':
            return 'Clôturer';
        default:
            return to;
    }
}

/** Écarter demande un motif ; confirmer une anomalie proposée se fait d'un clic. */
export function needsComment(from: AnomalyStateCode, to: AnomalyStateCode): 'required' | 'optional' | 'none' {
    if (to === 'DISMISSED') {
        return 'required';
    }
    return to === 'CONFIRMED' && from === 'PROPOSED' ? 'none' : 'optional';
}

export function isOpen(state: AnomalyStateCode): boolean {
    return state === 'PROPOSED' || state === 'CONFIRMED' || state === 'TRANSMITTED';
}
