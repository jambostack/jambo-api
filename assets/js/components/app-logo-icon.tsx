import { type SVGProps } from 'react';

export default function AppLogoIcon({ className, ...props }: SVGProps<SVGSVGElement>) {
    return (
        <svg
            viewBox="0 0 40 40"
            xmlns="http://www.w3.org/2000/svg"
            className={className}
            aria-hidden="true"
            {...props}
        >
            {/* J stem + hook — stroke uses currentColor */}
            <path
                className="fill-none stroke-current"
                d="M 25 7 L 25 26 Q 25 33 19 33 Q 13 33 13 27 L 13 24"
                strokeWidth="6"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            {/* API node accent dot */}
            <circle className="fill-current" cx="31" cy="8" r="2.5" opacity="0.5" />
        </svg>
    );
}
