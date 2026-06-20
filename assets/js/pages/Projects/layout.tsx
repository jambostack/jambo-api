import { ReactNode } from "react";

interface Props {
    children: ReactNode;
}

export default function ProjectsLayout({ children }: Props) {
    return (
        <div className="flex flex-col lg:flex-row lg:gap-6 gap-0 min-h-0 flex-1">
            {children}
        </div>
    );
}
