import { useEffect, useState } from 'react';

const MOBILE_BREAKPOINT = 768;
const MOBILE_MEDIA_QUERY = `(max-width: ${MOBILE_BREAKPOINT - 1}px)`;

function getIsMobile() {
    return typeof window !== 'undefined' && window.matchMedia(MOBILE_MEDIA_QUERY).matches;
}

export function useIsMobile() {
    const [isMobile, setIsMobile] = useState(getIsMobile);

    useEffect(() => {
        const mql = window.matchMedia(MOBILE_MEDIA_QUERY);

        const onChange = () => {
            setIsMobile(mql.matches);
        };

        onChange();

        if ('addEventListener' in mql) {
            mql.addEventListener('change', onChange);

            return () => mql.removeEventListener('change', onChange);
        }

        const legacyMql = mql as unknown as {
            addListener: (listener: () => void) => void;
            removeListener: (listener: () => void) => void;
        };

        legacyMql.addListener(onChange);

        return () => legacyMql.removeListener(onChange);
    }, []);

    return isMobile;
}
