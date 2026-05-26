// SOURCE: https://gist.github.com/ilkou/7bf2dbd42a7faf70053b43034fc4b5a4
import * as React from "react";
import type {
  ClearIndicatorProps,
  DropdownIndicatorProps,
  MenuListProps,
  MenuProps,
  MultiValueRemoveProps,
  OptionProps,
} from "react-select";
import { components } from "react-select";
import {
  CaretSortIcon,
  CheckIcon,
  Cross2Icon as CloseIcon,
} from "@radix-ui/react-icons";

export const DropdownIndicator = (props: DropdownIndicatorProps) => {
  return (
    <components.DropdownIndicator {...props}>
      <CaretSortIcon className={"h-4 w-4 opacity-50"} />
    </components.DropdownIndicator>
  );
};

export const ClearIndicator = (props: ClearIndicatorProps) => {
  return (
    <components.ClearIndicator {...props}>
      <CloseIcon className={"h-3.5 w-3.5 opacity-50"} />
    </components.ClearIndicator>
  );
};

export const MultiValueRemove = (props: MultiValueRemoveProps) => {
  return (
    <components.MultiValueRemove {...props}>
      <CloseIcon className={"h-3 w-3 opacity-50"} />
    </components.MultiValueRemove>
  );
};

export const Option = (props: OptionProps) => {
  return (
    <components.Option {...props}>
      <div className="flex items-center justify-between">
        {/* TODO: Figure out the type */}
        <div>{(props.data as { label: string }).label}</div>
        {props.isSelected && <CheckIcon />}
      </div>
    </components.Option>
  );
};

// Using Menu and MenuList fixes the scrolling behavior
export const Menu = (props: MenuProps) => {
  return <components.Menu {...props}>{props.children}</components.Menu>;
};

export const MenuList = (props: MenuListProps) => {
  return <components.MenuList {...props} />;
};
