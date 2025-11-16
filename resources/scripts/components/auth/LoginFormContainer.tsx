import React, { forwardRef } from 'react';
import { Form } from 'formik';
import styled from 'styled-components/macro';
import { breakpoint } from '@/theme';
import FlashMessageRender from '@/components/FlashMessageRender';
import tw from 'twin.macro';

type Props = React.DetailedHTMLProps<React.FormHTMLAttributes<HTMLFormElement>, HTMLFormElement> & {
    title?: string;
};

const Container = styled.div`
    ${tw`flex items-center justify-center min-h-screen`}
    
    ${breakpoint('sm')`
        ${tw`w-full px-4`}
    `};

    ${breakpoint('md')`
        ${tw`w-full`}
    `};

    ${breakpoint('lg')`
        ${tw`w-full`}
    `};

    ${breakpoint('xl')`
        ${tw`w-full`}
    `};
`;

export default forwardRef<HTMLFormElement, Props>(({ title, ...props }, ref) => (
    <Container>
        <div css={tw`w-full max-w-md`}>
            <div css={tw`flex justify-center mb-8`}>
                <img src={'https://dev.ogc.nz/img/svg/logo.svg'} css={tw`h-16`} alt="Logo" />
            </div>
            {title && <h2 css={tw`text-2xl text-center text-white font-medium mb-2`}>{title}</h2>}
            <p css={tw`text-center text-neutral-400 text-sm mb-8`}>Welcome to Ongamecloud</p>
            <FlashMessageRender css={tw`mb-4`} />
            <Form {...props} ref={ref}>
                <div css={tw`bg-neutral-800 rounded-lg p-6 shadow-xl border border-neutral-700`}>
                    {props.children}
                </div>
            </Form>
        </div>
    </Container>
));
