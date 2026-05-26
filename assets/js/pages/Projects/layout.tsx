import { ReactNode } from "react";

interface Props {
    children: ReactNode;
}

export default function ProjectsLayout({ children }: Props) {
    return (
        <div>
            <div className="flex flex-col lg:flex-row lg:space-y-0 lg:space-x-6">
                {children}
            </div>
        </div>
    );
}