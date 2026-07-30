import { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="15" y="7" width="34" height="50" rx="8" fill="none" stroke="currentColor" strokeWidth="4" />
            <path d="M32 17c1.4 8.7 5.3 12.6 14 14-8.7 1.4-12.6 5.3-14 14-1.4-8.7-5.3-12.6-14-14 8.7-1.4 12.6-5.3 14-14Z" fill="currentColor" />
        </svg>
    );
}
